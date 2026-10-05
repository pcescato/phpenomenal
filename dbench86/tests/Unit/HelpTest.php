<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DBench86\Cli\CommandDefinition;

class HelpTest extends TestCase
{
    public function testParameterDocumentationExtraction(): void
    {
        $docs = CommandDefinition::getParameterDocumentation();

        $this->assertTrue(isset($docs['database']), "Parameter database should be documented");
        $this->assertTrue(isset($docs['rows']), "Parameter rows should be documented");
        $this->assertTrue(isset($docs['runs']), "Parameter runs should be documented");
        $this->assertTrue(isset($docs['warmup']), "Parameter warmup should be documented");
        $this->assertTrue(isset($docs['batch_size']), "Parameter batch_size should be documented");
    }

    public function testHelpTextContainsAllParameters(): void
    {
        $helpText = CommandDefinition::getHelpText();

        $this->assertTrue(strpos($helpText, "--database") !== false, "Help text should contain --database");
        $this->assertTrue(strpos($helpText, "--rows") !== false, "Help text should contain --rows");
        $this->assertTrue(strpos($helpText, "--runs") !== false, "Help text should contain --runs");
        $this->assertTrue(strpos($helpText, "--batch-size") !== false, "Help text should contain --batch-size");
        $this->assertTrue(strpos($helpText, "--debug") !== false, "Help text should contain --debug");
    }

    public function testHelpTextContainsDescriptions(): void
    {
        $helpText = CommandDefinition::getHelpText();

        $this->assertTrue(strpos($helpText, "Moteur(s)") !== false, "Help text should contain database description");
        $this->assertTrue(strpos($helpText, "lignes") !== false, "Help text should contain rows description");
        $this->assertTrue(strpos($helpText, "Timeout") !== false, "Help text should contain timeout description");
    }

    public function run(): void
    {
        echo "\nHelpTest\n";
        echo "────────\n";
        $this->testParameterDocumentationExtraction();
        $this->testHelpTextContainsAllParameters();
        $this->testHelpTextContainsDescriptions();
    }
}

$helpTest = new HelpTest();
$helpTest->run();
