<?php

namespace App\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;

abstract class BaseController
{
    protected function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    protected function errorResponse(Response $response, array $errors, int $status = 400): Response
    {
        return $this->jsonResponse($response, ['errors' => $errors], $status);
    }

    protected function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $rulesArray = explode('|', $ruleString);
            
            foreach ($rulesArray as $rule) {
                if ($rule === 'required' && (empty($data[$field]) || $data[$field] === '')) {
                    $errors[$field][] = "The {$field} field is required.";
                }
                
                if (strpos($rule, 'email') !== false && !empty($data[$field])) {
                    if (!filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                        $errors[$field][] = "The {$field} must be a valid email.";
                    }
                }
                
                if (strpos($rule, 'min:') === 0 && !empty($data[$field])) {
                    $min = (int)substr($rule, 4);
                    if (strlen($data[$field]) < $min) {
                        $errors[$field][] = "The {$field} must be at least {$min} characters.";
                    }
                }
                
                if (strpos($rule, 'max:') === 0 && !empty($data[$field])) {
                    $max = (int)substr($rule, 4);
                    if (strlen($data[$field]) > $max) {
                        $errors[$field][] = "The {$field} may not be greater than {$max} characters.";
                    }
                }
                
                if (strpos($rule, 'in:') === 0 && !empty($data[$field])) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array($data[$field], $allowed)) {
                        $errors[$field][] = "The selected {$field} is invalid.";
                    }
                }
                
                if (strpos($rule, 'unique:') === 0 && !empty($data[$field])) {
                    $parts = explode(',', substr($rule, 7));
                    $table = $parts[0];
                    $column = $parts[1] ?? $field;
                    
                    $exists = \Illuminate\Database\Capsule\Manager::table($table)
                        ->where($column, $data[$field])
                        ->exists();
                        
                    if ($exists) {
                        $errors[$field][] = "The {$field} has already been taken.";
                    }
                }
                
                if ($rule === 'numeric' && !empty($data[$field]) && !is_numeric($data[$field])) {
                    $errors[$field][] = "The {$field} must be a number.";
                }
                
                if ($rule === 'integer' && !empty($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_INT)) {
                    $errors[$field][] = "The {$field} must be an integer.";
                }
                
                if (strpos($rule, 'between:') === 0 && !empty($data[$field])) {
                    $range = explode(',', substr($rule, 8));
                    $min = (int)$range[0];
                    $max = (int)$range[1];
                    if ($data[$field] < $min || $data[$field] > $max) {
                        $errors[$field][] = "The {$field} must be between {$min} and {$max}.";
                    }
                }
            }
        }

        return $errors;
    }
}