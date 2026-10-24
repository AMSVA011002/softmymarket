<?php
declare(strict_types=1);

/**
 * Small, dependency-free form validator.
 *
 * Rules are written as "required|string|max:120" so controllers stay readable
 * and every request is validated in one place before it reaches the database.
 * Anything not listed in $rules is dropped from the cleaned output, which makes
 * it safe to feed straight into Database::insert()/update().
 *
 * Supported rules: required, nullable, string, int, integer, numeric, bool,
 * email, url, image_url, phone, date, slug, min:n, max:n, between:n,m,
 * in:a,b,c, regex:/…/, same:field, confirmed, unique:table,column[,ignoreId],
 * exists:table,column
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $clean = [];

    /** @param array<string,mixed> $data @param array<string,string|array<int,string>> $rules */
    private function __construct(private array $data, private array $rules)
    {
    }

    /** @param array<string,mixed> $data @param array<string,string|array<int,string>> $rules */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    /** Validate and return [valid, errors, data]. */
    public function validate(): array
    {
        foreach ($this->rules as $field => $ruleset) {
            $rules = is_array($ruleset) ? $ruleset : explode('|', $ruleset);
            $this->applyField((string) $field, $rules);
        }
        return ['valid' => $this->errors === [], 'errors' => $this->errors, 'data' => $this->clean];
    }

    /** Convenience for controllers: validate or bounce back with flashes. */
    public static function guard(array $data, array $rules, string $redirectTo): array
    {
        $result = self::make($data, $rules)->validate();
        if (!$result['valid']) {
            remember_input($data);
            flash('danger', implode(' ', array_values($result['errors'])));
            redirect($redirectTo);
        }
        return $result['data'];
    }

    /** @param list<string> $rules */
    private function applyField(string $field, array $rules): void
    {
        $raw = $this->data[$field] ?? null;
        $nullable = in_array('nullable', $rules, true);
        $required = in_array('required', $rules, true);
        $isArray = in_array('array', $rules, true);

        if ($isArray) {
            $value = is_array($raw) ? array_values($raw) : [];
            if ($required && $value === []) {
                $this->errors[$field] = $this->label($field) . ' is required.';
                return;
            }
            $this->clean[$field] = $value;
            return;
        }

        $value = is_string($raw) ? trim($raw) : $raw;
        $isEmpty = $value === null || $value === '' || $value === [];

        if ($isEmpty) {
            if ($required) {
                $this->errors[$field] = $this->label($field) . ' is required.';
                return;
            }
            if ($nullable) {
                $this->clean[$field] = in_array('int', $rules, true) || in_array('integer', $rules, true) ? null : null;
                return;
            }
            if (in_array('bool', $rules, true)) {
                $this->clean[$field] = 0;
                return;
            }
            // Default the value to an empty string / zero so callers can rely
            // on the key being present.
            $this->clean[$field] = $value === null ? '' : (string) $value;
            return;
        }

        foreach ($rules as $rule) {
            if (in_array($rule, ['required', 'nullable', 'array'], true)) {
                continue;
            }
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            switch ($name) {
                case 'string':
                    if (!is_scalar($value)) {
                        $this->errors[$field] = $this->label($field) . ' must be text.';
                        return;
                    }
                    $value = (string) $value;
                    break;
                case 'int':
                case 'integer':
                    if (!is_numeric($value) || (string) (int) $value !== (string) $value && !is_int($value)) {
                        $this->errors[$field] = $this->label($field) . ' must be a whole number.';
                        return;
                    }
                    $value = (int) $value;
                    break;
                case 'numeric':
                    if (!is_numeric($value)) {
                        $this->errors[$field] = $this->label($field) . ' must be a number.';
                        return;
                    }
                    $value = (float) $value;
                    break;
                case 'bool':
                    $value = in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
                    break;
                case 'email':
                    if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                        $this->errors[$field] = 'Please enter a valid email address.';
                        return;
                    }
                    $value = strtolower((string) $value);
                    break;
                case 'url':
                case 'image_url':
                    if (!is_remote_url((string) $value)) {
                        $this->errors[$field] = $this->label($field) . ' must be a full http(s) address.';
                        return;
                    }
                    break;
                case 'phone':
                    if (!is_valid_phone((string) $value)) {
                        $this->errors[$field] = 'Please enter a valid Sri Lankan phone number.';
                        return;
                    }
                    $value = normalize_phone((string) $value);
                    break;
                case 'date':
                    $stamp = strtotime((string) $value);
                    if ($stamp === false) {
                        $this->errors[$field] = $this->label($field) . ' must be a date.';
                        return;
                    }
                    $value = date('Y-m-d', $stamp);
                    break;
                case 'slug':
                    $value = slugify((string) $value);
                    break;
                case 'min':
                    if (is_numeric($value) && !is_string($value)) {
                        if ((float) $value < (float) $arg) {
                            $this->errors[$field] = $this->label($field) . ' must be at least ' . $arg . '.';
                            return;
                        }
                    } elseif (mb_strlen((string) $value) < (int) $arg) {
                        $this->errors[$field] = $this->label($field) . ' must be at least ' . $arg . ' characters.';
                        return;
                    }
                    break;
                case 'max':
                    if (is_numeric($value) && !is_string($value)) {
                        if ((float) $value > (float) $arg) {
                            $this->errors[$field] = $this->label($field) . ' may not be greater than ' . $arg . '.';
                            return;
                        }
                    } elseif (mb_strlen((string) $value) > (int) $arg) {
                        $this->errors[$field] = $this->label($field) . ' may not be longer than ' . $arg . ' characters.';
                        return;
                    }
                    break;
                case 'between':
                    [$low, $high] = array_pad(explode(',', (string) $arg), 2, null);
                    if ((float) $value < (float) $low || (float) $value > (float) $high) {
                        $this->errors[$field] = $this->label($field) . ' must be between ' . $low . ' and ' . $high . '.';
                        return;
                    }
                    break;
                case 'in':
                    $options = explode(',', (string) $arg);
                    if (!in_array((string) $value, $options, true)) {
                        $this->errors[$field] = 'Choose a valid option for ' . strtolower($this->label($field)) . '.';
                        return;
                    }
                    break;
                case 'regex':
                    if (@preg_match((string) $arg, (string) $value) !== 1) {
                        $this->errors[$field] = $this->label($field) . ' is not in the expected format.';
                        return;
                    }
                    break;
                case 'same':
                    if ((string) $value !== (string) ($this->data[$arg] ?? '')) {
                        $this->errors[$field] = 'The ' . strtolower($this->label($field)) . ' does not match.';
                        return;
                    }
                    break;
                case 'unique':
                    [$table, $column, $ignoreId] = array_pad(explode(',', (string) $arg), 3, null);
                    if (!$this->isUnique((string) $table, (string) $column, (string) $value, $ignoreId !== null ? (int) $ignoreId : null)) {
                        $this->errors[$field] = 'That ' . strtolower($this->label($field)) . ' is already taken.';
                        return;
                    }
                    break;
                case 'exists':
                    [$table, $column] = array_pad(explode(',', (string) $arg), 2, 'id');
                    $found = Database::exists(
                        'SELECT 1 FROM ' . Database::identifier((string) $table) . ' WHERE ' . Database::identifier((string) $column) . ' = :v LIMIT 1',
                        ['v' => $value]
                    );
                    if (!$found) {
                        $this->errors[$field] = 'That ' . strtolower($this->label($field)) . ' could not be found.';
                        return;
                    }
                    break;
            }
            if (isset($this->errors[$field])) {
                return;
            }
        }

        $this->clean[$field] = $value;
    }

    /** Whitelisted identifier check keeps the unique/exists rules injection-free. */
    private function isUnique(string $table, string $column, string $value, ?int $ignoreId): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $table) || !preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $column)) {
            return true;
        }
        $sql = 'SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` = :v';
        $params = ['v' => $value];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }
        return !Database::exists($sql . ' LIMIT 1', $params);
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }
}
