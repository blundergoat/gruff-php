<?php

declare(strict_types=1);

namespace App;

use App\AliasReferencedClass as ReferencedAlias;

new UsedClass();
new TraitConsumer();
new ReferencedAlias();
new InterfaceConsumer();

$className = ClassConstantReferencedClass::class;
StaticReferencedClass::ping();
$enum = UsedEnum::One;
$closure = static fn(TypeReferencedClass $value): string => $className . $value::class;
$callable = first_class_callable_function(...);
$value = used_function() . USED_CONSTANT . $enum->name . $closure(new TypeReferencedClass()) . $callable();
