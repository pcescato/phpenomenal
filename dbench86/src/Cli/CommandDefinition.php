<?php
declare(strict_types=1);

namespace DBench86\Cli;

class CommandDefinition
{
    /**
     * Define 'run' command parameters with inline documentation via Parameter DocComments.
     * This is the single source of truth for command parameters.
     */
    public static function runCommand(
        /** Moteur(s) de base de données à benchmarker : all, mariadb, postgresql, sqlite */
        string $database = 'all',

        /** Nombre de lignes générées dans le jeu de données */
        int $rows = 100_000,

        /** Nombre d'itérations mesurées */
        int $runs = 30,

        /** Nombre d'itérations de préchauffage avant mesure */
        int $warmup = 5,

        /** Taille des lots d'insertion */
        int $batch_size = 100,

        /** Timeout maximal par test en secondes entières (> 0) ; fractions rejetées, 0 interdit */
        int $timeout = 30,

        /** Métrique de tri des latences réussies : min, median, p95, max */
        string $sort = 'median',

        /** Direction de tri : asc ou desc */
        string $direction = 'asc',

        /** Mode débogage (affiche les détails complets en cas d'erreur) */
        bool $debug = false,
    ): void {}

    /**
     * Extract parameter documentation from runCommand() method using Reflection.
     * Returns an array of parameter names and their docstrings.
     */
    public static function getParameterDocumentation(): array
    {
        $reflection = new \ReflectionMethod(self::class, 'runCommand');
        $parameters = $reflection->getParameters();
        $documentation = [];

        foreach ($parameters as $param) {
            $docComment = $param->getDocComment();
            if ($docComment !== null) {
                // Extract the text from /** ... */
                $docComment = trim($docComment);
                $docComment = preg_replace('/^\/\*\*\s*/', '', $docComment);
                $docComment = preg_replace('/\s*\*\/$/', '', $docComment);
                $docComment = preg_replace('/^\s*\*\s*/m', '', $docComment);
                $docComment = trim($docComment);

                $documentation[$param->name] = [
                    'name' => $param->name,
                    'docstring' => $docComment,
                    'default' => $param->getDefaultValue(),
                ];
            }
        }

        return $documentation;
    }

    /**
     * Generate help text from parameter documentation.
     */
    public static function getHelpText(): string
    {
        $docs = self::getParameterDocumentation();
        $help = "Usage: dbench86.php run [OPTIONS]\n\n";
        $help .= "Build: dbench86.php --build-monolith (requires canonical sources)\n\n";
        $help .= "Options:\n  --help, -h            Afficher cette aide\n";

        foreach ($docs as $param) {
            $name = str_replace('_', '-', $param['name']);
            $default = $param['default'] ?? 'null';

            if (is_string($default)) {
                $default = "'{$default}'";
            } elseif (is_int($default)) {
                $default = (string)$default;
            } elseif (is_bool($default)) {
                $default = $default ? 'true' : 'false';
            } elseif ($default === null) {
                $default = 'null';
            }

            $help .= sprintf(
                "  --%-20s  %s (default: %s)\n",
                $name,
                $param['docstring'],
                $default
            );
        }

        return $help;
    }
}
