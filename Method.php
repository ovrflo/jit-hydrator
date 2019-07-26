<?php

namespace Ovrflo\JitHydrator;

/**
 * @author Catalin Dan <dancatalin18@gmail.com>
 */
class Method
{
    /** @var string */
    private $visibility = 'private';
    /** @var bool */
    private $isStatic = false;
    /** @var bool */
    private $isAbstract = false;
    /** @var string */
    private $name;
    /** @var array */
    private $arguments = [];
    /** @var null|string */
    private $returnType;
    /** @var string[] */
    private $throws = [];

    private $indentation = 1;
    private $lines = [];

    public function __construct(string $name, ...$args)
    {
        $this->name = $name;
        foreach ($args as $arg) {
            call_user_func_array([$this, 'addArgument'], $arg);
        }
    }

    public function addArgument(string $name, ?string $type = null, ?string $defaultValue = null, bool $isReference = false): self
    {
        $this->arguments[$name] = [
            'name' => $name,
            'type' => $type,
            'defaultValue' => $defaultValue,
            'isReference' => $isReference,
        ];

        return $this;
    }

    public function addThrows(string $exception)
    {
        $this->throws[] = $exception;

        return $this;
    }

    public function indent()
    {
        $this->indentation += 1;

        return $this;
    }

    public function outdent()
    {
        $this->indentation -= 1;

        return $this;
    }

    public function writeln(string $line = '')
    {
        $this->lines[] = [
            'type' => 'line',
            'indentation' => $this->indentation,
            'text' => trim($line),
        ];

        return $this;
    }

    public function writeIf(string $condition)
    {
        return $this->writeln('if (' . $condition . ") {")->indent();
    }

    public function writeElse()
    {
        return $this->outdent()->writeln('} else {')->indent();
    }

    public function writeElseIf(string $condition)
    {
        return $this->outdent()->writeln('} else if (' . $condition . ') {')->indent();
    }

    public function writeEndif()
    {
        return $this->outdent()->writeln('}');
    }


    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getName(): string
    {
        return $this->name;
    }

    private function printDocBlock()
    {
        if ($this->returnType || count($this->arguments) || count($this->throws)) {
            $string = "/**\n";
            foreach ($this->arguments as $argument) {
                $string .= ' * @param ' . (null !== $argument['type'] ? $argument['type'] : 'mixed') . ' ' . ($argument['isReference'] ? '&' : '') . '$' . $argument['name'] . "\n";
            }
            if ($this->returnType) {
                $string .= (count($this->arguments) > 0 ? " *\n" : '');
                $string .= ' * @return ' . $this->returnType . "\n";
            }
            if (count($this->throws)) {
                $string .= (count($this->arguments) > 0 || $this->returnType ? " *\n" : '');
                foreach ($this->throws as $exception) {
                    $string .= ' * @throws ' . $exception . "\n";
                }
            }
            $string .= " */\n";

            return $string;
        }
    }

    private function printBody(int $extraIndent = 0, array $options = [])
    {
        $indented = function (int $level, $text) {
            return str_repeat('    ', $level) . $text;
        };

        $symbols = ['this' => 'this'];
        $uniqId = uniqid('');
        $callable = function ($matches) use (&$symbols, $uniqId) {
            if (!isset($symbols[$matches[1]])) {
                $symbols[$matches[1]] = $matches[1] . '_' . $uniqId;
            }

            return '$' . $symbols[$matches[1]];
        };

        $body = '';
        $lastLineIndex = count($this->lines) - 1;
        $hasReturn = false;
        foreach ($this->lines as $index => $line) {
            if ($line['type'] === 'line') {
                if (isset($options['mangle']) && $options['mangle']) {
                    $line['text'] = preg_replace_callback('#\\$([a-zA-Z_][a-zA-Z0-9_]+)\\b#i', $callable, $line['text']);
                }
                if (isset($options['inline']) && $options['inline'] && preg_match('#\\s*return\\s?(.*)#', $line['text'], $matches)) {
                    $hasReturn = true;
                    $line['text'] = isset($options['assign']) ? '// return' . ($matches[1] ? ' ' . $matches[1] : '') . "\n" . $indented($line['indentation'] + $extraIndent, $options['assign'].' = '.$matches[1])."\n" : '';
                    if ($lastLineIndex !== $index) {
                        $line['text'] .= $indented($line['indentation'] + $extraIndent, 'goto after_inlined_' . $this->name . '_' . $uniqId . ';');
                    }
                }
                $body .= $indented($line['indentation'] + $extraIndent, $line['text']) . "\n";
            } elseif ($line['type'] === 'call') {
                /** @var Method $target */
                $target = $line['target'];
                $args = [];
                foreach ($target->getArguments() as $argument) {
                    if (isset($line['arguments'][$argument['name']])) {
                        $args[$argument['name']] = $line['arguments'][$argument['name']];
                    } elseif (!$argument['defaultValue']) {
                        throw new \Exception(sprintf('Missing argument "%s" when calling "%s".', $argument['name'], $target->getName()));
                    }
                }

                if ($line['inline']) {
                    $body .= $indented($line['indentation'] + $extraIndent, '// inline call of $this->' . $target->getName()) . '(' . implode(', ', $args) . ')' . "\n";
                    $body .= $target->dumpInline($line['indentation'] + $extraIndent, $line);
                } else {
                    $body .= $indented($line['indentation'] + $extraIndent, '// call ' . $target->getName()) . "\n";
                    $assign = isset($line['assign']) ? $line['assign'] . ' = ' : '';
                    $body .= $indented($line['indentation'] + $extraIndent, sprintf('%s$this->' . $target->getName() . '(%s);' . "\n", $assign, implode(', ', $args)));
                }
            }
        }

        if (isset($options['inline']) && $options['inline']) {
            if (count($options['arguments'])) {
                $body = "\n" . $body;
            }

            foreach ($this->arguments as $name => $argument) {
                if (isset($options['arguments'][$name])) {
                    $value = ($argument['isReference'] ? '&' : '') . $options['arguments'][$name];
                } elseif ($argument['defaultValue'] !== null) {
                    $value = $argument['defaultValue'];
                } else {
                    throw new \Exception(sprintf('Cannot inline call of "%s". Missing argument "%s".', $this->name, $name));
                }
                $body = $indented($extraIndent + 1, '$' . $symbols[$name]. ' = ' . $value . '; // assign argument $' . $name . "\n") . $body;
            }

            $unsetSymbols = [];
            foreach ($symbols as $symbol) {
                if ($symbol == 'this') {
                    continue;
                }
                $unsetSymbols[] = '$' . $symbol;
            }

            if (!$hasReturn && isset($options['assign'])) {
                $body .= $indented($extraIndent + 1, '// insert auto-return since return is missing' .":\n");
                $body .= $indented($extraIndent + 1, $options['assign'] . ' = null;' ."\n");
            }


            $afterBodyLabelName = 'after_inlined_'.$this->name.'_'.$uniqId;
            $body .= strpos($body, $afterBodyLabelName) === false ? '' : "\n" . $indented($extraIndent + 1, $afterBodyLabelName.":\n");
            if (count($unsetSymbols)) {
                $body .= $indented($extraIndent + 1, 'unset(' . implode(', ', $unsetSymbols) .");\n");
            }
        }

        return $body;
    }

    public function dump()
    {
        $string = $this->printDocBlock();
        $string .= ($this->isAbstract ? 'abstract ' : '') . ($this->isStatic ? 'static ' : '') . $this->visibility . ' function ' . $this->name . ' (';
        if (count($this->arguments)) {
            $printedArguments = [];
            foreach ($this->arguments as $argument) {
                $printedArguments[] = (null !== $argument['type'] ? $argument['type'] . ' ' : '') . ($argument['isReference'] ? '&' : '') . '$' . $argument['name'] . (null !== $argument['defaultValue'] ? ' = ' . $argument['defaultValue'] : '');
            }
            $string .= implode(', ', $printedArguments);
        }
        $string .= ')' . (null !== $this->returnType ? ': ' . $this->returnType : '') . "\n{\n";
        $string .= $this->printBody();
        $string .= "\n}";

        return $string;
    }

    public function dumpInline(int $indent = 0, array $options = [])
    {
        $string = str_repeat('    ', $indent) . "{\n";
        $string .= $this->printBody($indent, $options);
        $string .= str_repeat('    ', $indent) . "}\n";

        return $string;
    }

    public function call(Method $target, array $arguments, array $options): self
    {
        $this->lines[] = array_merge([
            'type' => 'call',
            'target' => $target,
            'arguments' => $arguments,
            'inline' => false,
            'mangle' => true,
            'indentation' => $this->indentation,
            'assign' => null,
        ], $options);

        return $this;
    }

    public function setVisibility(string $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setIsStatic(bool $isStatic): self
    {
        $this->isStatic = $isStatic;
        return $this;
    }

    public function isStatic(): bool
    {
        return $this->isStatic;
    }

    public function setIsAbstract(bool $isAbstract): self
    {
        $this->isAbstract = $isAbstract;
        return $this;
    }

    public function isAbstract(): bool
    {
        return $this->isAbstract;
    }

    public function setReturnType(?string $returnType): self
    {
        $this->returnType = $returnType;
        return $this;
    }

    public function getReturnType(): ?string
    {
        return $this->returnType;
    }
}
