<?php
namespace Lysine\DataMapper {
    class Types {
        use \Lysine\Traits\Singleton;

        // type实例
        protected $types = array();

        protected $type_classes = array();

        public function get($type) {
            $type = strtolower($type);

            if ($type == 'int') {
                $type = 'integer';
            } elseif ($type == 'text') {
                $type = 'string';
            }

            if (!isset($this->type_classes[$type]))
                $type = 'mixed';

            if (isset($this->types[$type]))
                return $this->types[$type];

            $class = $this->type_classes[$type];
            return $this->types[$type] = new $class;
        }

        public function register($type, $class) {
            $type = strtolower($type);
            $this->type_classes[$type] = $class;

            return $this;
        }

        static public function normalizeAttribute(array $attribute) {
            $defaults = array(
                'allow_null' => false,
                'auto_generate' => false,
                'default' => null,
                'pattern' => null,
                'primary_key' => false,
                'protected' => false,
                'refuse_update' => false,
                'strict' => null,
                'type' => null,
            );

            $type = isset($attribute['type']) ? $attribute['type'] : null;

            $attribute = array_merge(
                $defaults,
                self::getInstance()->get($type)->normalizeAttribute($attribute)
            );

            if ($attribute['primary_key']) {
                $attribute['allow_null'] = false;
                $attribute['refuse_update'] = true;
            }

            if ($attribute['protected'] && $attribute['strict'] === null) {
                $attribute['strict'] = true;
            }

            return $attribute;
        }
    }

    \Lysine\DataMapper\Types::getInstance()
        ->register('mixed', '\Lysine\DataMapper\Types\Mixed')
        ->register('datetime', '\Lysine\DataMapper\Types\DateTime')
        ->register('integer', '\Lysine\DataMapper\Types\Integer')
        ->register('json', '\Lysine\DataMapper\Types\Json')
        ->register('numeric', '\Lysine\DataMapper\Types\Numeric')
        ->register('pg_array', '\Lysine\DataMapper\Types\PgsqlArray')
        ->register('pg_hstore', '\Lysine\DataMapper\Types\PgsqlHstore')
        ->register('string', '\Lysine\DataMapper\Types\String')
        ->register('uuid', '\Lysine\DataMapper\Types\UUID');
}

namespace Lysine\DataMapper\Types {
    class Mixed {
        public function normalizeAttribute(array $attribute) {
            return $attribute;
        }

        public function normalize($value, array $attribute) {
            return $value;
        }

        public function store($value, array $attribute) {
            return $value;
        }

        public function restore($value, array $attribute) {
            return $this->normalize($value, $attribute);
        }

        public function getDefaultValue(array $attribute) {
            return $attribute['default'];
        }

        public function toJSON($value, array $attribute) {
            return $value;
        }
    }

    class Numeric extends Mixed {
        public function normalize($value, array $attribute) {
            return $value * 1;
        }
    }

    class Integer extends Numeric {
        public function normalize($value, array $attribute) {
            return (int)$value;
        }
    }

    class String extends Mixed {
        public function normalize($value, array $attribute) {
            return (string)$value;
        }
    }

    class Json extends Mixed {
        public function normalizeAttribute(array $attribute) {
            return array_merge(array(
                'default' => array(),
                'strict' => true,
            ), $attribute);
        }

        public function normalize($value, array $attribute) {
            return is_array($value) ? $value : json_decode($value, true);
        }

        public function store($value, array $attribute) {
            if ($value === array()) {
                return null;
            }

            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }
    }

    class Datetime extends Mixed {
        public function normalize($value, array $attribute) {
            if ($value instanceof \DateTime) {
                return $value;
            }

            if (!isset($attribute['format'])) {
                return new \DateTime($value);
            }

            if (!$value = \DateTime::createFromFormat($attribute['format'], $value))
                throw new \Exception('Create datetime from format ['.$attribute['format'].'] failed!');

            return $value;
        }

        public function store($value, array $attribute) {
            if ($value instanceof \DateTime) {
                $format = isset($attribute['format']) ? $attribute['format'] : 'c'; // ISO 8601
                $value = $value->format($format);
            }

            return $value;
        }

        public function getDefaultValue(array $attribute) {
            return ($attribute['default'] === null)
                 ? null
                 : new \DateTime($attribute['default']);
        }
    }

    class UUID extends Mixed {
        public function normalizeAttribute(array $attribute) {
            $attribute = array_merge(array(
                'upper' => false,
            ), $attribute);

            if (isset($attribute['primary_key']) && $attribute['primary_key']) {
                $attribute['auto_generate'] = true;
            }

            return $attribute;
        }

        public function getDefaultValue(array $attribute) {
            if (!$attribute['auto_generate']) {
                return $attribute['default'];
            }

            $uuid = self::generate();

            if ($attribute['upper']) {
                $uuid = strtoupper($uuid);
            }

            return $uuid;
        }

        // http://php.net/manual/en/function.uniqid.php#94959
        static public function generate() {
            return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        }
    }

    class PgsqlArray extends Mixed {
        public function normalizeAttribute(array $attribute) {
            return array_merge(array(
                'default' => array(),
                'strict' => true,
            ), $attribute);
        }

        public function normalize($value, array $attribute) {
            if ($value === null) {
                return array();
            }

            if (!is_array($value)) {
                throw new \UnexpectedValueException('Postgresql array must be of the type array');
            }

            return $value;
        }

        public function store($value, array $attribute) {
            return \Lysine\Service\DB\Adapter\Pgsql::encodeArray($value);
        }

        public function restore($value, array $attribute) {
            return \Lysine\Service\DB\Adapter\Pgsql::decodeArray($value);
        }
    }

    class PgsqlHstore extends Mixed {
        public function normalizeAttribute(array $attribute) {
            return array_merge(array(
                'default' => array(),
                'strict' => true,
            ), $attribute);
        }

        public function normalize($value, array $attribute) {
            if ($value === null) {
                return array();
            }

            if (!is_array($value)) {
                throw new \UnexpectedValueException('Postgresql hstore must be of the type array');
            }

            return $value;
        }

        public function store($value, array $attribute) {
            return \Lysine\Service\DB\Adapter\Pgsql::encodeHstore($value);
        }

        public function restore($value, array $attribute) {
            return \Lysine\Service\DB\Adapter\Pgsql::decodeHstore($value);
        }
    }
}