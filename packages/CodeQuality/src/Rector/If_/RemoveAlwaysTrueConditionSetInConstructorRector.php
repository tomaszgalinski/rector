<?php declare(strict_types=1);

namespace Rector\CodeQuality\Rector\If_;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeVisitor;
use PHPStan\Type\ObjectType;
use Rector\BetterPhpDocParser\Ast\NodeTraverser;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\Manipulator\ClassManipulator;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://phpstan.org/r/e909844a-084e-427e-92ac-fed3c2aeabab
 * @see \Rector\CodeQuality\Tests\Rector\If_\RemoveAlwaysTrueConditionSetInConstructorRector\RemoveAlwaysTrueConditionSetInConstructorRectorTest
 */
final class RemoveAlwaysTrueConditionSetInConstructorRector extends AbstractRector
{
    /**
     * @var ClassManipulator
     */
    private $classManipulator;

    public function __construct(ClassManipulator $classManipulator)
    {
        $this->classManipulator = $classManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('If conditions is always true, perform the content right away', [
            new CodeSample(
                <<<'CODE_SAMPLE'
final class SomeClass
{
    private $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function go()
    {
        if ($this->value) {
            return 'yes';
        }
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
final class SomeClass
{
    private $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function go()
    {
        return 'yes';
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [If_::class];
    }

    /**
     * @param If_ $node
     */
    public function refactor(Node $node): ?Node
    {
        // just one if
        if (count($node->elseifs) !== 0) {
            return null;
        }

        if ($node->else !== null) {
            return null;
        }

        // only property fetch, because of constructor set
        if (! $node->cond instanceof PropertyFetch) {
            return null;
        }

        $propertyFetchValue = $this->resolvePropertyFetchValue($node->cond);
        if ($propertyFetchValue === null) {
            return null;
        }

        // is object → always true
        if (! $propertyFetchValue instanceof ObjectType) {
            return null;
        }

        // wrap to expressoins
        $expressions = [];
        foreach ($node->stmts as $stmt) {
            return $stmt;

            // @todo resolve later
            $expressions[] = new Node\Stmt\Expression($stmt);
        }

        return $expressions;
    }

    private function resolvePropertyFetchValue(PropertyFetch $propertyFetch): ?\PHPStan\Type\Type
    {
        /** @var Class_ $class */
        $class = $propertyFetch->getAttribute(AttributeKey::CLASS_NODE);

        $propertyName = $this->getName($propertyFetch);

        /** @var Node\Stmt\PropertyProperty $property */
        $propertyProperty = $this->classManipulator->getProperty($class, $propertyName);
        if ($propertyProperty === null) {
            return null;
        }

        /** @var Node\Stmt\Property $property */
        $property = $propertyProperty->getAttribute(AttributeKey::PARENT_NODE);
        // anything but private can be changed from outer scope
        if (! $property->isPrivate()) {
            return null;
        }

        // A. set in constructor
        $constructClassMethod = $class->getMethod('__construct');
        if ($constructClassMethod === null) {
            return null;
        }

        $resolvedType = null;
        $this->traverseNodesWithCallable($constructClassMethod->stmts, function (Node $node) use ($propertyName, &$resolvedType) {
            if (! $node instanceof PropertyFetch) {
                return null;
            }

            if (! $this->isName($node, $propertyName)) {
                return null;
            }

            $parentNode = $node->getAttribute(AttributeKey::PARENT_NODE);
            if (! $parentNode instanceof Node\Expr\Assign) {
                return null;
            }

            $resolvedType = $this->getStaticType($parentNode->expr);

            return \PhpParser\NodeTraverser::STOP_TRAVERSAL;
        });

        return $resolvedType;
    }
}
