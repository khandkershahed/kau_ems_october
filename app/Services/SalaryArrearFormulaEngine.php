<?php

namespace App\Services;

use DomainException;

/** An intentionally small, bounded expression interpreter: no eval, SQL, PHP, or JS. */
final class SalaryArrearFormulaEngine
{
    private array $tokens = [];
    private int $at = 0;

    /** Syntax-only check: avoid rejecting valid formulas because test values divide by zero. */
    public function validate(string $expression): void
    {
        $this->compile($expression);
    }

    private function compile(string $expression): array
    {
        if (strlen($expression) > 400) throw new DomainException('Formula is too long (max 400 characters).');
        $rest = strtoupper(trim($expression));
        $this->tokens = [];
        while ($rest !== '') {
            $rest = ltrim($rest);
            if ($rest === '') break;
            if (!preg_match('/^\s*(>=|<=|==|!=|[()+*\/,<>\-=]|[A-Z_][A-Z0-9_]*|\d+(?:\.\d+)?)/', $rest, $match)) {
                throw new DomainException('Unsupported character or operator in arrear formula.');
            }
            $this->tokens[] = $match[1];
            $rest = substr($rest, strlen($match[0]));
            if (count($this->tokens) > 200) throw new DomainException('Too many tokens in formula.');
        }
        if (!$this->tokens) throw new DomainException('Formula is empty.');
        $this->at=0;
        $node=$this->compare();
        if ($this->at !== count($this->tokens)) throw new DomainException('Unexpected token in formula.');
        return $node;
    }

    public function evaluate(string $expression, array $variables, array $months = []): float
    {
        $node = $this->compile($expression);
        return round($this->run($node, $variables, $months, 0), 2, PHP_ROUND_HALF_UP);
    }

    private function peek(): ?string { return $this->tokens[$this->at] ?? null; }
    private function eat(): string { return $this->tokens[$this->at++] ?? ''; }
    private function compare(): array
    {
        $node = $this->sum();
        while (in_array($this->peek(), ['>','<','>=','<=','==','!=','='], true)) {
            $op = $this->eat(); $node = ['binary', $op, $node, $this->sum()];
        }
        return $node;
    }
    private function sum(): array
    {
        $node = $this->product();
        while (in_array($this->peek(), ['+','-'], true)) {
            $op = $this->eat(); $node = ['binary', $op, $node, $this->product()];
        }
        return $node;
    }
    private function product(): array
    {
        $node = $this->atom();
        while (in_array($this->peek(), ['*','/'], true)) {
            $op = $this->eat(); $node = ['binary', $op, $node, $this->atom()];
        }
        return $node;
    }
    private function atom(): array
    {
        $token = $this->eat();
        if ($token === '') throw new DomainException('Incomplete formula.');
        if ($token === '+' || $token === '-') return ['unary', $token, $this->atom()];
        if ($token === '(') {
            $node = $this->compare();
            if ($this->eat() !== ')') throw new DomainException('Missing closing parenthesis.');
            return $node;
        }
        if (is_numeric($token)) return ['number', (float) $token];
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $token)) {
            if ($this->peek() !== '(') return ['variable', $token];
            $this->eat(); $args = [];
            if ($this->peek() !== ')') {
                do {
                    $args[] = $this->compare();
                    if ($this->peek() !== ',') break;
                    $this->eat();
                } while (true);
            }
            if ($this->eat() !== ')') throw new DomainException('Invalid function arguments.');
            if (!in_array($token, ['MIN','MAX','ROUND','IF','SUM_MONTHS'], true)) {
                throw new DomainException('Unsupported formula function '.$token);
            }
            return ['function', $token, $args];
        }
        throw new DomainException('Invalid formula token.');
    }
    private function run(array $n, array $vars, array $months, int $depth): float
    {
        if ($depth > 50) throw new DomainException('Formula complexity limit exceeded.');
        switch ($n[0]) {
            case 'number': return $n[1];
            case 'variable':
                $name = $n[1];
                if (!array_key_exists($name, $vars) || !is_numeric($vars[$name])) {
                    throw new DomainException('Missing verified source value: '.$name);
                }
                return (float) $vars[$name];
            case 'unary': return ($n[1] === '-' ? -1 : 1) * $this->run($n[2], $vars, $months, $depth + 1);
            case 'binary':
                $a = $this->run($n[2], $vars, $months, $depth + 1);
                $b = $this->run($n[3], $vars, $months, $depth + 1);
                $v = match ($n[1]) {
                    '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
                    '/' => abs($b) < 0.00000001 ? throw new DomainException('Division by zero.') : $a / $b,
                    '>' => (float) ($a > $b), '<' => (float) ($a < $b),
                    '>=' => (float) ($a >= $b), '<=' => (float) ($a <= $b),
                    '==','=' => (float) ($a == $b), '!=' => (float) ($a != $b),
                    default => throw new DomainException('Unsupported operator.'),
                };
                if (!is_finite($v)) throw new DomainException('Formula produced an invalid number.');
                return $v;
            case 'function':
                [$unused, $fn, $args] = $n;
                if ($fn === 'SUM_MONTHS') {
                    if (count($args) !== 1 || !$months) throw new DomainException('SUM_MONTHS requires verified month records.');
                    $total = 0;
                    foreach ($months as $month) {
                        $total += $this->run($args[0], array_replace($vars, $month), [], $depth + 1);
                    }
                    return $total;
                }
                if ($fn === 'IF') {
                    if (count($args) !== 3) throw new DomainException('IF needs condition, true, false.');
                    return $this->run($args[$this->run($args[0], $vars, $months, $depth + 1) != 0 ? 1 : 2], $vars, $months, $depth + 1);
                }
                $numbers = array_map(fn ($arg) => $this->run($arg, $vars, $months, $depth + 1), $args);
                return match ($fn) {
                    'MIN' => count($numbers) >= 2 ? min($numbers) : throw new DomainException('MIN needs two values.'),
                    'MAX' => count($numbers) >= 2 ? max($numbers) : throw new DomainException('MAX needs two values.'),
                    'ROUND' => count($numbers) === 1 || count($numbers) === 2
                        ? round($numbers[0], max(0, min(2, (int) ($numbers[1] ?? 0))), PHP_ROUND_HALF_UP)
                        : throw new DomainException('ROUND needs one or two values.'),
                    default => throw new DomainException('Unsupported function.'),
                };
        }
        throw new DomainException('Invalid formula tree.');
    }
}
