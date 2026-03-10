<?php

namespace App\Http\Controllers;

use App\Models\Ambulance;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AmbulanceController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        $query = Ambulance::query();
        
        $status = $request->getQueryParams()['status'] ?? null;
        if ($status) {
            $query->where('status', $status);
        }

        $ambulances = $query->get();
        return $this->jsonResponse($response, ['ambulances' => $ambulances]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $errors = $this->validate($data, [
            'vehicle_number' => 'required|string|unique:ambulances',
            'license_plate' => 'required|string|unique:ambulances',
            'type' => 'required|in:basic_life_support,advanced_life_support,specialty_care',
            'equipment_level' => 'required|string',
            'paramedic_count' => 'required|integer|min:1'
        ]);

        if (!empty($errors)) {
            return $this->errorResponse($response, $errors, 422);
        }

        $ambulance = Ambulance::create([
            'vehicle_number' => $data['vehicle_number'],
            'license_plate' => $data['license_plate'],
            'type' => $data['type'],
            'status' => Ambulance::STATUS_AVAILABLE,
            'equipment_level' => $data['equipment_level'],
            'paramedic_count' => $data['paramedic_count']
        ]);

        return $this->jsonResponse($response, [
            'ambulance' => $ambulance
        ], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $ambulance = Ambulance::with('emergencies')->findOrFail($args['id']);
        return $this->jsonResponse($response, ['ambulance' => $ambulance]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $ambulance = Ambulance::findOrFail($args['id']);
        $data = $request->getParsedBody();

        $ambulance->update($data);

        return $this->jsonResponse($response, [
            'ambulance' => $ambulance->fresh()
        ]);
    }

    public function updateLocation(Request $request, Response $response, array $args): Response
    {
        $ambulance = Ambulance::findOrFail($args['id']);
        $data = $request->getParsedBody();

        $errors = $this->validate($data, [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric'
        ]);

        if (!empty($errors)) {
            return $this->errorResponse($response, $errors, 422);
        }

        $ambulance->updateLocation($data['latitude'], $data['longitude']);

        return $this->jsonResponse($response, [
            'ambulance' => $ambulance->fresh(),
            'message' => 'Location updated successfully'
        ]);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $ambulance = Ambulance::findOrFail($args['id']);
        $data = $request->getParsedBody();

        $errors = $this->validate($data, [
            'status' => 'required|in:available,en_route,on_scene,transporting,maintenance,offline'
        ]);

        if (!empty($errors)) {
            return $this->errorResponse($response, $errors, 422);
        }

        $ambulance->update(['status' => $data['status']]);

        return $this->jsonResponse($response, [
            'ambulance' => $ambulance->fresh(),
            'message' => 'Status updated successfully'
        ]);
    }

    public function nearby(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $lat = (float)($params['lat'] ?? 0);
        $lng = (float)($params['lng'] ?? 0);
        $radius = (float)($params['radius'] ?? 10);

        $ambulances = Ambulance::available()
            ->selectRaw("*, (
                6371 * acos(
                    cos(radians(?)) * cos(radians(current_latitude)) * 
                    cos(radians(current_longitude) - radians(?)) + 
                    sin(radians(?)) * sin(radians(current_latitude))
                )
            ) AS distance", [$lat, $lng, $lat])
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->get();

        return $this->jsonResponse($response, ['ambulances' => $ambulances]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $ambulance = Ambulance::findOrFail($args['id']);
        $ambulance->delete();

        return $this->jsonResponse($response, [
            'message' => 'Ambulance deleted successfully'
        ]);
    }
}
