<?php

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

    /** @var string[] */
    private $uses = [];
    /** @var array */
    private $properties = [];
    /** @var Method[] */
    private $methods = [];

    public function __construct(string $className = 'CustomInlinedHydrator', string $namespace = 'App\\Doctrine\\Hydrator')
    {
        $this->className = $className;
        $this->namespace = $namespace;
    }

    public function dump(bool $forEval = false)
    {
        $body = '';

        if (count($this->properties)) {
            foreach ($this->properties as $property) {
                $body .= (null !== $property['type'] ? '    /** @var ' . $property['type'] . ($property['description'] ? ' ' . $property['description'] : '') . ' */' . "\n" : '');
                $body .= '    ' . ($property['isStatic'] ? 'static ' : '') . $property['visibility'] . ' $' . $property['name'] . (null !== $property['defaultValue'] ? ' = ' . $property['defaultValue'] : '') . ";\n";
            }
            $body .= "\n";
        }

        foreach ($this->methods as $name => $method) {
            $body .= '    ' . str_replace("\n", "\n    ", $method->dump());
            $body .= "\n\n";
        }

        return $forEval ? $this->generateClassForEval($body) : $this->generateClass($body);
    }

    public function addProperty(string $name, string $visibility = 'private', string $defaultValue = null, string $type = null, bool $isStatic = false, string $description = null)
    {
        $this->properties[$name] = [
            'name' => $name,
            'visibility' => $visibility,
            'defaultValue' => $defaultValue,
            'type' => $type,
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
        return str_replace(['%body%', '%name%', '%namespace%', '%uses%'], [$body, $this->className, $this->namespace, count($this->uses) ? "\n\n" . implode("\n", $uses) : ''], $template);
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
