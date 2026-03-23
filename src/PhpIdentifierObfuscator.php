<?php

namespace Php\LaravelObfuscator;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class PhpIdentifierObfuscator
{
    public const VAR_PREFIX = '__phpv_';
    public const METHOD_PREFIX = '__phpm_';
    public const PROP_PREFIX = '__phpp_';

    public const SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_COOKIE',
        '_SERVER',
        '_ENV',
        '_FILES',
        '_REQUEST',
        'GLOBALS',
    ];

    // Keep artisan/framework metadata intact so deobfuscation tooling keeps working.
    public const NEVER_RENAME_METHODS = [
        '__construct',
        '__invoke',
        'handle',
        'boot',
        'register',
    ];

    public const NEVER_RENAME_PROPERTIES = [
        'signature',
        'description',
        'middleware',
        'except',
        'only',
    ];

    public function __construct(private ObfuscationCodec $codec, private bool $aggressive = false)
    {
    }

    public function obfuscate(string $phpCode): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($phpCode);
        if (!is_array($ast)) {
            throw new \RuntimeException('Failed to parse PHP file');
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($this->codec, $this->aggressive) extends NodeVisitorAbstract {
            public function __construct(private ObfuscationCodec $codec, private bool $aggressive)
            {
            }

            private array $classMethodMap = [];
            private array $classPropMap = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $methodMap = [];
                    foreach ($node->getMethods() as $m) {
                        // Default: rename private only. Aggressive: include protected too.
                        if (!($m->isPrivate() || ($this->aggressive && $m->isProtected()))) {
                            continue;
                        }

                        $old = $m->name->toString();
                        if (str_starts_with($old, '__') || in_array($old, PhpIdentifierObfuscator::NEVER_RENAME_METHODS, true)) {
                            continue;
                        }

                        $methodMap[$old] = PhpIdentifierObfuscator::METHOD_PREFIX . $this->codec->obfuscate('m_', $old);
                    }

                    $propMap = [];
                    foreach ($node->getProperties() as $p) {
                        // Default: rename private only. Aggressive: include protected too.
                        if (!($p->isPrivate() || ($this->aggressive && $p->isProtected()))) {
                            continue;
                        }

                        foreach ($p->props as $pp) {
                            $old = $pp->name->toString();
                            if (str_starts_with($old, '__') || in_array($old, PhpIdentifierObfuscator::NEVER_RENAME_PROPERTIES, true)) {
                                continue;
                            }

                            $propMap[$old] = PhpIdentifierObfuscator::PROP_PREFIX . $this->codec->obfuscate('p_', $old);
                        }
                    }

                    $this->classMethodMap[] = $methodMap;
                    $this->classPropMap[] = $propMap;
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    $map = $this->currentMethodMap();
                    $name = $node->name->toString();
                    if (isset($map[$name])) {
                        $node->name = new Node\Identifier($map[$name]);
                    }
                }

                if ($node instanceof Node\Expr\MethodCall) {
                    $map = $this->currentMethodMap();
                    if ($node->name instanceof Node\Identifier) {
                        $name = $node->name->toString();
                        if (isset($map[$name]) && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                            $node->name = new Node\Identifier($map[$name]);
                        }
                    }
                }

                if ($node instanceof Node\Expr\StaticCall) {
                    $map = $this->currentMethodMap();
                    if ($node->name instanceof Node\Identifier) {
                        $name = $node->name->toString();
                        if (isset($map[$name]) && $node->class instanceof Node\Name && in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true)) {
                            $node->name = new Node\Identifier($map[$name]);
                        }
                    }
                }

                if ($node instanceof Node\Stmt\PropertyProperty) {
                    $map = $this->currentPropMap();
                    $name = $node->name->toString();
                    if (isset($map[$name])) {
                        $node->name = new Node\VarLikeIdentifier($map[$name]);
                    }
                }

                if ($node instanceof Node\Expr\PropertyFetch) {
                    $map = $this->currentPropMap();
                    if ($node->name instanceof Node\Identifier) {
                        $name = $node->name->toString();
                        if (isset($map[$name]) && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                            $node->name = new Node\Identifier($map[$name]);
                        }
                    }
                }

                if ($node instanceof Node\Expr\StaticPropertyFetch) {
                    $map = $this->currentPropMap();
                    if ($node->name instanceof Node\VarLikeIdentifier) {
                        $name = $node->name->toString();
                        if (isset($map[$name]) && $node->class instanceof Node\Name && in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true)) {
                            $node->name = new Node\VarLikeIdentifier($map[$name]);
                        }
                    }
                }

                if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                    $name = $node->name;
                    if ($name === 'this' || in_array($name, PhpIdentifierObfuscator::SUPERGLOBALS, true)) {
                        return null;
                    }
                    if (str_starts_with($name, PhpIdentifierObfuscator::VAR_PREFIX)) {
                        return null;
                    }

                    $node->name = PhpIdentifierObfuscator::VAR_PREFIX . $this->codec->obfuscate('v_', $name);
                }

                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    array_pop($this->classMethodMap);
                    array_pop($this->classPropMap);
                }

                return null;
            }

            private function currentMethodMap(): array
            {
                return $this->classMethodMap !== [] ? $this->classMethodMap[count($this->classMethodMap) - 1] : [];
            }

            private function currentPropMap(): array
            {
                return $this->classPropMap !== [] ? $this->classPropMap[count($this->classPropMap) - 1] : [];
            }
        });

        $ast = $traverser->traverse($ast);
        return (new Standard())->prettyPrintFile($ast);
    }

    public function deobfuscate(string $phpCode): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($phpCode);
        if (!is_array($ast)) {
            throw new \RuntimeException('Failed to parse PHP file');
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($this->codec) extends NodeVisitorAbstract {
            public function __construct(private ObfuscationCodec $codec)
            {
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $name = $node->name->toString();
                    $decoded = $this->decode(PhpIdentifierObfuscator::METHOD_PREFIX, 'm_', $name);
                    if ($decoded !== null) {
                        $node->name = new Node\Identifier($decoded);
                    }
                }

                if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
                    $name = $node->name->toString();
                    $decoded = $this->decode(PhpIdentifierObfuscator::METHOD_PREFIX, 'm_', $name);
                    if ($decoded !== null) {
                        $node->name = new Node\Identifier($decoded);
                    }
                }

                if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
                    $name = $node->name->toString();
                    $decoded = $this->decode(PhpIdentifierObfuscator::METHOD_PREFIX, 'm_', $name);
                    if ($decoded !== null) {
                        $node->name = new Node\Identifier($decoded);
                    }
                }

                if ($node instanceof Node\Stmt\PropertyProperty) {
                    $name = $node->name->toString();
                    $decoded = $this->decode(PhpIdentifierObfuscator::PROP_PREFIX, 'p_', $name);
                    if ($decoded !== null) {
                        $node->name = new Node\VarLikeIdentifier($decoded);
                    }
                }

                if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
                    $name = $node->name->toString();
                    $decoded = $this->decode(PhpIdentifierObfuscator::PROP_PREFIX, 'p_', $name);
                    if ($decoded !== null) {
                        $node->name = new Node\Identifier($decoded);
                    }
                }

                if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
                    $name = $node->name->toString();
                    $decoded = $this->decode(PhpIdentifierObfuscator::PROP_PREFIX, 'p_', $name);
                    if ($decoded !== null) {
                        $node->name = new Node\VarLikeIdentifier($decoded);
                    }
                }

                if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                    $name = $node->name;
                    $decoded = $this->decode(PhpIdentifierObfuscator::VAR_PREFIX, 'v_', $name);
                    if ($decoded !== null) {
                        $node->name = $decoded;
                    }
                }

                return null;
            }

            private function decode(string $prefix, string $typePrefix, string $name): ?string
            {
                if (!str_starts_with($name, $prefix)) {
                    return null;
                }

                $rest = substr($name, strlen($prefix));
                return $this->codec->deobfuscate($typePrefix, $rest);
            }
        });

        $ast = $traverser->traverse($ast);
        return (new Standard())->prettyPrintFile($ast);
    }
}

