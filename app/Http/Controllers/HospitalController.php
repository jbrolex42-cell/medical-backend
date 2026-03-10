<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HospitalController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $query = Hospital::where('is_active', true);
        
        $traumaLevel = $request->getQueryParams()['trauma_level'] ?? null;
        if ($traumaLevel) {
            $query->where('trauma_level', $traumaLevel);
        }

        $hospitals = $query->get();
        return $this->jsonResponse($response, ['hospitals' => $hospitals]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $errors = $this->validate($data, [
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'phone' => 'required|string',
            'emergency_capacity' => 'required|integer|min:1',
            'trauma_level' => 'required|integer|between:1,5',
            'specialties' => 'nullable|array'
        ]);

        if (!empty($errors)) {
            return $this->errorResponse($response, $errors, 422);
        }

        $hospital = Hospital::create([
            'name' => $data['name'],
            'address' => $data['address'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'phone' => $data['phone'],
            'emergency_capacity' => $data['emergency_capacity'],
            'current_occupancy' => 0,
            'trauma_level' => $data['trauma_level'],
            'specialties' => $data['specialties'] ?? [],
            'is_active' => true,
            'average_wait_time' => $data['average_wait_time'] ?? 0
        ]);

        return $this->jsonResponse($response, [
            'hospital' => $hospital
        ], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $hospital = Hospital::with('emergencies')->findOrFail($args['id']);
        return $this->jsonResponse($response, ['hospital' => $hospital]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $hospital = Hospital::findOrFail($args['id']);
        $data = $request->getParsedBody();

        $hospital->update($data);

        return $this->jsonResponse($response, [
            'hospital' => $hospital->fresh()
        ]);
    }

    public function updateOccupancy(Request $request, Response $response, array $args): Response
    {
        $hospital = Hospital::findOrFail($args['id']);
        $data = $request->getParsedBody();

        $errors = $this->validate($data, [
            'current_occupancy' => 'required|integer|min:0'
        ]);

        if (!empty($errors)) {
            return $this->errorResponse($response, $errors, 422);
        }

        if ($data['current_occupancy'] > $hospital->emergency_capacity) {
            return $this->errorResponse($response, [
                'message' => 'Occupancy cannot exceed capacity'
            ], 422);
        }

        $hospital->update(['current_occupancy' => $data['current_occupancy']]);

        return $this->jsonResponse($response, [
            'hospital' => $hospital->fresh(),
            'message' => 'Occupancy updated successfully'
        ]);
    }

    public function nearby(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $lat = (float)($params['lat'] ?? 0);
        $lng = (float)($params['lng'] ?? 0);
        $radius = (float)($params['radius'] ?? 20);

        $hospitals = Hospital::where('is_active', true)
            ->selectRaw("*, (
                6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) * 
                    cos(radians(longitude) - radians(?)) + 
                    sin(radians(?)) * sin(radians(latitude))
                )
            ) AS distance", [$lat, $lng, $lat])
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->get();

        return $this->jsonResponse($response, ['hospitals' => $hospitals]);
    }

    public function availability(Request $request, Response $response): Response
    {
        $hospitals = Hospital::where('is_active', true)
            ->whereRaw('current_occupancy < emergency_capacity')
            ->orderBy('trauma_level')
            ->get();

        return $this->jsonResponse($response, ['hospitals' => $hospitals]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $hospital = Hospital::findOrFail($args['id']);
        $hospital->update(['is_active' => false]);

        return $this->jsonResponse($response, [
            'message' => 'Hospital deactivated successfully'
        ]);
    }
}