<?php

namespace App\Traits;

use Symfony\Component\Yaml\Yaml;

trait EnvironmentVariableProtection
{
    /**
     * Check if an environment variable is protected from deletion
     *
     * @param  string  $key  The environment variable key to check
     * @return bool True if the variable is protected, false otherwise
     */
    protected function isProtectedEnvironmentVariable(string $key): bool
    {
        return str($key)->startsWith('SERVICE_FQDN_') || str($key)->startsWith('SERVICE_URL_') || str($key)->startsWith('SERVICE_NAME_');
    }

    /**
     * Check if an environment variable is used in Docker Compose
     *
     * Only values can reference a variable. In `FOO: ${BAR}` the key `FOO` is the
     * name the container sees, not a reference to a Coolify variable, so matching
     * on keys would block the deletion of unrelated variables.
     *
     * @param  string  $key  The environment variable key to check
     * @param  string|null  $dockerCompose  The Docker Compose YAML content
     * @return array [bool $isUsed, string $reason] Whether the variable is used and the reason if it is
     */
    protected function isEnvironmentVariableUsedInDockerCompose(string $key, ?string $dockerCompose): array
    {
        if (empty($dockerCompose)) {
            return [false, ''];
        }

        try {
            $dockerComposeData = Yaml::parse($dockerCompose);
        } catch (\Exception $e) {
            // If there's an error parsing the Docker Compose file, we'll assume it's not used
            return [false, ''];
        }

        if (! is_array($dockerComposeData)) {
            return [false, ''];
        }

        if ($this->isReferencedInComposeValues($dockerComposeData, $key)) {
            return [true, "Environment variable '{$key}' is referenced in the Docker Compose file."];
        }

        return [false, ''];
    }

    /**
     * Recursively check whether any string value interpolates the given variable,
     * as $KEY, ${KEY} or ${KEY:-default} (and the other modifier forms).
     */
    private function isReferencedInComposeValues(array $values, string $key): bool
    {
        $pattern = '/\$\{?'.preg_quote($key, '/').'(?![A-Za-z0-9_])/';

        foreach ($values as $value) {
            if (is_array($value)) {
                if ($this->isReferencedInComposeValues($value, $key)) {
                    return true;
                }

                continue;
            }

            if (is_string($value) && preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
