<?php

namespace App\Events;

use App\Models\Emergency;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmergencyCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $emergency;

    public function __construct(Emergency $emergency)
    {
        $this->emergency = $emergency->load('patient');
    }

    public function broadcastOn()
    {
        return [
            new Channel('emergencies'),
            new PrivateChannel('emergency.' . $this->emergency->id),
            new Channel('dispatchers')
        ];
    }

    public function broadcastAs()
    {
        return 'emergency.created';
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->emergency->id,
            'latitude' => $this->emergency->latitude,
            'longitude' => $this->emergency->longitude,
            'triage_category' => $this->emergency->triage_category,
            'symptoms' => $this->emergency->symptoms,
            'status' => $this->emergency->status,
            'created_at' => $this->emergency->created_at,
            'patient_name' => $this->emergency->patient->name ?? 'Unknown',
            'assigned_ambulance' => $this->emergency->ambulance
        ];
    }
}