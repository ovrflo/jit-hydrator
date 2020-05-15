<?php
declare(strict_types=1);

namespace Ovrflo\JitHydrator;

/**
 * @author Catalin Dan <dancatalin18@gmail.com>
 */
class ClassWriter
{
    /** @var string */
    private $namespace;
    /** @var string */
    private $className;
    /** @var bool */
    private $strictTypes;
    /** @var bool */
    private $propertyTypeHint;

    /** @var string[] */
    private $uses = [];
    /** @var array */
    private $properties = [];
    /** @var Method[] */
    private $methods = [];

    public function __construct(string $className = 'CustomInlinedHydrator', string $namespace = 'App\\Doctrine\\Hydrator', bool $strictTypes = false, bool $propertyTypeHint = false)
    {
        $this->className = $className;
        $this->namespace = $namespace;
        $this->strictTypes = $strictTypes;
        $this->propertyTypeHint = $propertyTypeHint;
    }

    public function dump(bool $forEval = false)
    {
        $body = '';

        if (count($this->properties)) {
            foreach ($this->properties as $property) {
                $body .= (null !== $property['type'] ? '    /** @var ' . ($property['nullable'] === true ? '?' : '') . $property['type'] . ($property['description'] ? ' ' . $property['description'] : '') . ' */' . "\n" : '');
                $body .= '    ' . ($property['isStatic'] ? 'static ' : '') . $property['visibility'];
                if (\PHP_VERSION_ID >= 70400 && $this->propertyTypeHint && null !== $property['type'] && (preg_match('#^\\??(array|bool|int|float|string|iterable|object|self|parent)$#', $property['type']) || class_exists($property['type']))) {
                    $body .= ' ' . ($property['nullable'] === true ? '?' : '') . $property['type'];
                }
                $body .= ' $' . $property['name'] . (null !== $property['defaultValue'] ? ' = ' . $property['defaultValue'] : '') . ";\n";
            }
            $body .= "\n";
        }

        foreach ($this->methods as $name => $method) {
            $body .= '    ' . str_replace("\n", "\n    ", $method->dump());
            $body .= "\n\n";
        }

        return $forEval ? $this->generateClassForEval($body) : $this->generateClass($body);
    }

    public function addProperty(string $name, string $visibility = 'private', string $defaultValue = null, string $type = null, ?bool $nullable = null, bool $isStatic = false, string $description = null)
    {
        $this->properties[$name] = [
            'name' => $name,
            'visibility' => $visibility,
            'defaultValue' => $defaultValue,
            'type' => $type,
            'nullable' => $nullable,
            'isStatic' => $isStatic,
            'description' => $description,
        ];

        return $this;
    }

    public function addUse(string $class, ?string $alias = null)
    {
        $this->uses[$class] = $alias;

        return $this;
    }

    public function createMethod(string $name, ...$args)
    {
        return $this->methods[$name] = new Method($name, ...$args);
    }

    private function generateClass(string $body)
    {
        $template = <<<'EOT'
<?php
%strictTypes%
namespace %namespace%;%uses%

class %name%
{
%body%
}

EOT;
        $uses = [];
        foreach ($this->uses as $class => $alias) {
            $uses[] = sprintf('use %s%s;', $class, $alias !== null ? ' as ' . $alias : '');
        }
        $strictTypes = $this->strictTypes ? "declare(strict_types=1);\n" : '';

        return str_replace(['%strictTypes%', '%body%', '%name%', '%namespace%', '%uses%'], [$strictTypes, $body, $this->className, $this->namespace, count($this->uses) ? "\n\n" . implode("\n", $uses) : ''], $template);
    }

    private function generateClassForEval(string $body)
    {
        $template = <<<'EOT'
<?php

namespace %namespace% {
%uses%

class %name%
{
%body%
}
}

EOT;
        $uses = [];
        foreach ($this->uses as $class => $alias) {
            $uses[] = sprintf('use %s%s;', $class, $alias !== null ? ' as ' . $alias : '');
        }
        return str_replace(['%body%', '%name%', '%namespace%', '%uses%'], [$body, $this->className, $this->namespace, count($this->uses) ? "\n\n" . implode("\n", $uses) : ''], $template);
    }
}
