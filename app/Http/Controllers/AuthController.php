<?php

namespace App\Http\Controllers;

use App\Models\User;
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController extends BaseController
{
    /**
     * Register User
     */
    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (!$data) {
            return $this->errorResponse($response, ['message' => 'Invalid request body'], 400);
        }

        $errors = $this->validate($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'required|string|unique:users,phone',
            'role' => 'required|in:admin,dispatcher,paramedic,citizen'
        ]);

        if (!empty($errors)) {
            return $this->errorResponse($response, $errors, 422);
        }

        $user = User::create([
            'name' => trim($data['name']),
            'email' => strtolower($data['email']),
            'password' => password_hash($data['password'], PASSWORD_BCRYPT),
            'phone' => $data['phone'],
            'role' => $data['role'],
            'is_active' => true
        ]);

        $token = $this->generateToken($user);

        unset($user->password);

        return $this->jsonResponse($response, [
            'user' => $user,
            'token' => $token
        ], 201);
    }

    /**
     * Login User
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (!$data) {
            return $this->errorResponse($response, ['message' => 'Invalid request body'], 400);
        }

        $errors = $this->validate($data, [
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        if (!empty($errors)) {
            return $this->errorResponse($response, $errors, 422);
        }

        $user = User::where('email', strtolower($data['email']))->first();

        if (!$user || !password_verify($data['password'], $user->password)) {
            return $this->errorResponse($response, [
                'message' => 'Invalid email or password'
            ], 401);
        }

        if (!$user->is_active) {
            return $this->errorResponse($response, [
                'message' => 'Account is deactivated'
            ], 403);
        }

        $token = $this->generateToken($user);

        unset($user->password);

        return $this->jsonResponse($response, [
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * Get Authenticated User
     */
    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        unset($user->password);

        return $this->jsonResponse($response, [
            'user' => $user
        ]);
    }

    /**
     * Refresh JWT Token
     */
    public function refresh(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        $token = $this->generateToken($user);

        return $this->jsonResponse($response, [
            'token' => $token
        ]);
    }

    /**
     * Generate JWT Token
     */
    private function generateToken(User $user): string
    {
        $payload = [
            'iss' => $_ENV['APP_NAME'] ?? 'EmergencySystem',
            'aud' => 'emergency-users',
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + (int)($_ENV['JWT_EXPIRATION'] ?? 3600),
            'sub' => $user->id,
            'email' => $user->email,
            'role' => $user->role
        ];

        return JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    }
}
