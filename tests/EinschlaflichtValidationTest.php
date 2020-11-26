<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class EinschlaflichtValidationTest extends TestCaseSymconValidation
{
    public function testValidateEinschlaflicht(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateEinschlaflichtModule(): void
    {
        $this->validateModule(__DIR__ . '/../Einschlaflicht');
    }
}