<?php declare(strict_types=1);
namespace Phan\Language\Element;

use Phan\CodeBase;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NullType;
use ast\Node\Decl;

trait FunctionTrait {

    /**
     * @return int
     */
    abstract public function getPhanFlags() : int;

    /**
     * @param int $phan_flags
     *
     * @return void
     */
    abstract public function setPhanFlags(int $phan_flags);


    /**
     * @var int
     * The number of required parameters for the method
     */
    private $number_of_required_parameters = 0;

    /**
     * @var int
     * The number of optional parameters for the method
     */
    private $number_of_optional_parameters = 0;

    /**
     * @var Parameter[]
     * The list of parameters for this method
     */
    private $parameter_list = [];

    /**
     * @return int
     * The number of optional parameters on this method
     */
    public function getNumberOfOptionalParameters() : int {
        return $this->number_of_optional_parameters;
    }

    /**
     * The number of optional parameters
     *
     * @return void
     */
    public function setNumberOfOptionalParameters(int $number) {
        $this->number_of_optional_parameters = $number;
    }

    /**
     * @return int
     * The maximum number of parameters to this method
     */
    public function getNumberOfParameters() : int {
        return (
            $this->getNumberOfRequiredParameters()
            + $this->getNumberOfOptionalParameters()
        );
    }

    /**
     * @return int
     * The number of required parameters on this method
     */
    public function getNumberOfRequiredParameters() : int {
        return $this->number_of_required_parameters;
    }

    /**
     *
     * The number of required parameters
     *
     * @return void
     */
    public function setNumberOfRequiredParameters(int $number) {
        $this->number_of_required_parameters = $number;
    }

    /**
     * @return bool
     * True if this method had no return type defined when it
     * was defined (either in the signature itself or in the
     * docblock).
     */
    public function isReturnTypeUndefined() : bool
    {
        return Flags::bitVectorHasState(
            $this->getPhanFlags(),
            Flags::IS_RETURN_TYPE_UNDEFINED
        );
    }

    /**
     * @param bool $is_return_type_undefined
     * True if this method had no return type defined when it
     * was defined (either in the signature itself or in the
     * docblock).
     *
     * @return void
     */
    public function setIsReturnTypeUndefined(
        bool $is_return_type_undefined
    ) {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::IS_RETURN_TYPE_UNDEFINED,
            $is_return_type_undefined
        ));
    }

    /**
     * @return bool
     * True if this method returns a value
     * (i.e. it has a return with an expression)
     */
    public function getHasReturn() : bool
    {
        return Flags::bitVectorHasState(
            $this->getPhanFlags(),
            Flags::HAS_RETURN
        );
    }

    /**
     * @return bool
     * True if this method yields any value(i.e. it is a \Generator)
     */
    public function getHasYield() : bool
    {
        return Flags::bitVectorHasState(
            $this->getPhanFlags(),
            Flags::HAS_YIELD
        );
    }

    /**
     * @param bool $has_return
     * Set to true to mark this method as having a
     * return value
     *
     * @return void
     */
    public function setHasReturn(bool $has_return)
    {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::HAS_RETURN,
            $has_return
        ));
    }

    /**
     * @param bool $has_yield
     * Set to true to mark this method as having a
     * yield value
     *
     * @return void
     */
    public function setHasYield(bool $has_yield)
    {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::HAS_YIELD,
            $has_yield
        ));
    }

    /**
     * @return Parameter[]
     * A list of parameters on the method
     */
    public function getParameterList() {
        return $this->parameter_list;
    }

    /**
     * Gets the $ith parameter for the **caller**.
     * In the case of variadic arguments, an infinite number of parameters exist.
     * (The callee would see variadic arguments(T ...$args) as a single variable of type T[],
     * while the caller sees a place expecting an expression of type T.
     *
     * @param int $i - offset of the parameter.
     * @return Parameter|null The parameter type that the **caller** observes.
     */
    public function getParameterForCaller(int $i) {
        $list = $this->parameter_list;
        if (count($list) === 0) {
            return null;
        }
        $parameter = $list[$i] ?? null;
        if ($parameter) {
            return $parameter->asNonVariadic();
        }
        $lastParameter = $list[count($list) - 1];
        if ($lastParameter->isVariadic()) {
            return $lastParameter->asNonVariadic();
        }
        return null;
    }

    /**
     * @param Parameter[] $parameter_list
     * A list of parameters to set on this method
     *
     * @return void
     */
    public function setParameterList(array $parameter_list) {
        $this->parameter_list = $parameter_list;
    }

    /**
     * @param Parameter $parameter
     * A parameter to append to the parameter list
     *
     * @return void
     */
    public function appendParameter(Parameter $parameter) {
        $this->parameter_list[] = $parameter;
    }

    /**
     * Adds types from comments to the params of a user-defined function or method.
     * Also adds the types from defaults, and emits warnings for certain violations.
     *
     * Conceptually, Func and Method should have defaults/comments analyzed in the same way.
     *
     * This does nothing if $function is for an internal method.
     *
     * @param Context $context
     * The context in which the node appears
     *
     * @param CodeBase $code_base
     *
     * @param Decl $node
     * An AST node representing a method
     *
     * @param FunctionInterface $function - A Func or Method to add params to the local scope of.
     *
     * @param Comment $comment - processed doc comment of $node, with params
     *
     * @return void
     */
    public static function addParamsToScopeOfFunctionOrMethod(
        Context $context,
        CodeBase $code_base,
        Decl $node,
        FunctionInterface $function,
        Comment $comment
    ) {
        if ($function->isInternal()) {
            return;
        }
        $parameter_offset = 0;
        foreach ($function->getParameterList() as $i => $parameter) {
            if ($parameter->getUnionType()->isEmpty()) {
                // If there is no type specified in PHP, check
                // for a docComment with @param declarations. We
                // assume order in the docComment matches the
                // parameter order in the code
                if ($comment->hasParameterWithNameOrOffset(
                    $parameter->getName(),
                    $parameter_offset
                )) {
                    $comment_param = $comment->getParameterWithNameOrOffset(
                        $parameter->getName(),
                        $parameter_offset
                    );
                    $comment_param_type = $comment_param->getUnionType();
                    if ($parameter->isVariadic() !== $comment_param->isVariadic()) {
                        Issue::maybeEmit(
                            $code_base,
                            $context,
                            $parameter->isVariadic() ? Issue::TypeMismatchVariadicParam : Issue::TypeMismatchVariadicComment,
                            $node->lineno ?? 0,
                            $comment_param->__toString(),
                            $parameter->__toString()
                        );
                    }

                    $parameter->addUnionType($comment_param_type);
                }
            }

            // If there's a default value on the parameter, check to
            // see if the type of the default is cool with the
            // specified type.
            if ($parameter->hasDefaultValue()) {
                $default_type = $parameter->getDefaultValueType();
                $defaultIsNull = $default_type->isEqualTo(
                    NullType::instance(false)->asUnionType());
                // If the default type isn't null and can't cast
                // to the parameter's declared type, emit an
                // issue.
                if (!$defaultIsNull) {
                    if (!$default_type->canCastToUnionType(
                        $parameter->getUnionType()
                    )) {
                        Issue::maybeEmit(
                            $code_base,
                            $context,
                            Issue::TypeMismatchDefault,
                            $node->lineno ?? 0,
                            (string)$parameter->getUnionType(),
                            $parameter->getName(),
                            (string)$default_type
                        );
                    }
                }

                // If there are no types on the parameter, the
                // default shouldn't be treated as the one
                // and only allowable type.
                if ($parameter->getUnionType()->isEmpty()) {
                    $parameter->addUnionType(
                        MixedType::instance(false)->asUnionType()
                    );
                }

                // If we have no other type info about a parameter,
                // just because it has a default value of null
                // doesn't mean that is its type. Any type can default
                // to null
                if ($defaultIsNull) {
                    if (!$parameter->getUnionType()->isEmpty()) {
                        $parameter->getUnionType()->addType(
                            NullType::instance(false)
                        );
                    }
                } else {
                    // If default type is not null, then add the default type to the parameter type
                    $parameter->addUnionType($default_type);
                }
            }

            ++$parameter_offset;
        }
    }
}
