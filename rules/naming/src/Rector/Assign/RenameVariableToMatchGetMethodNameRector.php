<?php

declare(strict_types=1);

namespace Rector\Naming\Rector\Assign;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Type\TypeWithClassName;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\FamilyTree\Reflection\FamilyRelationsAnalyzer;
use Rector\Naming\Guard\BreakingVariableRenameGuard;
use Rector\Naming\Matcher\VariableAndCallAssignMatcher;
use Rector\Naming\Naming\ExpectedNameResolver;
use Rector\Naming\NamingConvention\NamingConventionAnalyzer;
use Rector\Naming\PhpDoc\VarTagValueNodeRenamer;
use Rector\Naming\ValueObject\VariableAndCallAssign;
use Rector\Naming\VariableRenamer;

/**
 * @see \Rector\Naming\Tests\Rector\Assign\RenameVariableToMatchGetMethodNameRector\RenameVariableToMatchGetMethodNameRectorTest
 */
final class RenameVariableToMatchGetMethodNameRector extends AbstractRector
{
    /**
     * @var ExpectedNameResolver
     */
    private $expectedNameResolver;

    /**
     * @var VariableRenamer
     */
    private $variableRenamer;

    /**
     * @var BreakingVariableRenameGuard
     */
    private $breakingVariableRenameGuard;

    /**
     * @var FamilyRelationsAnalyzer
     */
    private $familyRelationsAnalyzer;

    /**
     * @var VariableAndCallAssignMatcher
     */
    private $variableAndCallAssignMatcher;

    /**
     * @var NamingConventionAnalyzer
     */
    private $namingConventionAnalyzer;

    /**
     * @var VarTagValueNodeRenamer
     */
    private $varTagValueNodeRenamer;

    public function __construct(
        ExpectedNameResolver $expectedNameResolver,
        VariableRenamer $variableRenamer,
        BreakingVariableRenameGuard $breakingVariableRenameGuard,
        FamilyRelationsAnalyzer $familyRelationsAnalyzer,
        VariableAndCallAssignMatcher $variableAndCallAssignMatcher,
        NamingConventionAnalyzer $namingConventionAnalyzer,
        VarTagValueNodeRenamer $varTagValueNodeRenamer
    ) {
        $this->expectedNameResolver = $expectedNameResolver;
        $this->variableRenamer = $variableRenamer;
        $this->breakingVariableRenameGuard = $breakingVariableRenameGuard;
        $this->familyRelationsAnalyzer = $familyRelationsAnalyzer;
        $this->variableAndCallAssignMatcher = $variableAndCallAssignMatcher;
        $this->namingConventionAnalyzer = $namingConventionAnalyzer;
        $this->varTagValueNodeRenamer = $varTagValueNodeRenamer;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Rename variable to match get method name', [
            new CodeSample(
                <<<'PHP'
class SomeClass
{
    public function run()
    {
        $a = $this->getRunner();
    }
}
PHP
,
                <<<'PHP'
class SomeClass
{
    public function run()
    {
        $runner = $this->getRunner();
    }
}
PHP
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Assign::class];
    }

    /**
     * @param Assign $node
     */
    public function refactor(Node $node): ?Node
    {
        $variableAndCallAssign = $this->variableAndCallAssignMatcher->match($node);
        if ($variableAndCallAssign === null) {
            return null;
        }

        $expectedName = $this->expectedNameResolver->resolveForGetCallExpr($variableAndCallAssign->getCall());
        if ($expectedName === null || $this->isName($node, $expectedName)) {
            return null;
        }

        if ($this->shouldSkip($variableAndCallAssign, $expectedName)) {
            return null;
        }

        return $this->renameVariable($variableAndCallAssign, $expectedName);
    }

    private function renameVariable(VariableAndCallAssign $variableAndCallAssign, string $newName): Assign
    {
        $this->varTagValueNodeRenamer->renameAssignVarTagVariableName(
            $variableAndCallAssign->getAssign(),
            $variableAndCallAssign->getVariableName(),
            $newName
        );

        $this->variableRenamer->renameVariableInFunctionLike(
            $variableAndCallAssign->getFunctionLike(),
            $variableAndCallAssign->getAssign(),
            $variableAndCallAssign->getVariableName(),
            $newName
        );

        return $variableAndCallAssign->getAssign();
    }

    /**
     * @param StaticCall|MethodCall|FuncCall $expr
     */
    private function isClassTypeWithChildren(Expr $expr): bool
    {
        $callStaticType = $this->getStaticType($expr);
        if (! $callStaticType instanceof TypeWithClassName) {
            return false;
        }

        return $this->familyRelationsAnalyzer->isParentClass($callStaticType->getClassName());
    }

    private function shouldSkip(VariableAndCallAssign $variableAndCallAssign, string $expectedName): bool
    {
        if ($this->namingConventionAnalyzer->isCallMatchingVariableName(
            $variableAndCallAssign->getCall(),
            $variableAndCallAssign->getVariableName(),
            $expectedName
        )) {
            return true;
        }

        if ($this->isClassTypeWithChildren($variableAndCallAssign->getCall())) {
            return true;
        }

        return $this->breakingVariableRenameGuard->shouldSkipVariable(
            $variableAndCallAssign->getVariableName(),
            $expectedName,
            $variableAndCallAssign->getFunctionLike(),
            $variableAndCallAssign->getVariable()
        );
    }
}
