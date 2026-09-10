<?php
/**
 * Simple Validation Helper
 */

/**
 * Validate an array of inputs against rules
 * Returns array of errors (empty = passed)
 */
function validate(array $data, array $rules): array
{
    $errors = [];

    foreach ($rules as $field => $ruleSet) {
        $ruleList = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
        $value = $data[$field] ?? '';

        foreach ($ruleList as $rule) {
            $params = [];

            // Check for parameterized rules like "min:3"
            if (str_contains($rule, ':')) {
                $parts = explode(':', $rule);
                $rule = $parts[0];
                $params = array_slice($parts, 1);
            }

            switch ($rule) {
                case 'required':
                    if (trim((string) $value) === '') {
                        $errors[$field][] = ucfirst($field) . ' is required.';
                    }
                    break;

                case 'email':
                    if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field][] = ucfirst($field) . ' must be a valid email.';
                    }
                    break;

                case 'numeric':
                    if ($value !== '' && !is_numeric($value)) {
                        $errors[$field][] = ucfirst($field) . ' must be a number.';
                    }
                    break;

                case 'min':
                    $min = (int) ($params[0] ?? 0);
                    if (strlen((string) $value) < $min) {
                        $errors[$field][] = ucfirst($field) . ' must be at least ' . $min . ' characters.';
                    }
                    break;

                case 'max':
                    $max = (int) ($params[0] ?? 255);
                    if (strlen((string) $value) > $max) {
                        $errors[$field][] = ucfirst($field) . ' must not exceed ' . $max . ' characters.';
                    }
                    break;

                case 'unique':
                    $table = $params[0] ?? '';
                    $column = $params[1] ?? $field;
                    $except = $params[2] ?? null;

                    if ($table && $value !== '') {
                        $stmt = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?" . ($except ? " AND id != ?" : ""));
                        $bindParams = [$value];
                        if ($except) $bindParams[] = $except;
                        $stmt->execute($bindParams);

                        if ($stmt->fetchColumn() > 0) {
                            $errors[$field][] = ucfirst($field) . ' already exists.';
                        }
                    }
                    break;

                case 'exists':
                    $table = $params[0] ?? '';
                    $column = $params[1] ?? 'id';
                    if ($table && $value !== '') {
                        $stmt = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
                        $stmt->execute([$value]);
                        if ($stmt->fetchColumn() === 0) {
                            $errors[$field][] = ucfirst($field) . ' does not exist.';
                        }
                    }
                    break;
            }
        }
    }

    return $errors;
}

/**
 * Store old input in session
 */
function preserve_input(array $data): void
{
    $_SESSION['_old'] = $data;
}
