<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Patient;
use App\Models\EmtResponder;
use App\Models\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{

    /*
    |--------------------------------------------------------------------------
    | REGISTER
    |--------------------------------------------------------------------------
    */

    public function register(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:100',
            'password' => 'required|string|min:8',
            'role' => 'required|in:patient,emt,admin,hospital',
            'phone' => 'nullable|string|unique:users',
            'email' => 'nullable|email|unique:users',
            'blood_group' => 'nullable|string|max:10',
            'allergies' => 'nullable|string',
            'medical_conditions' => 'nullable|string',
            'medications' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string|max:100',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'license_number' => 'nullable|string|required_if:role,emt',
            'qualification_level' => 'nullable|string|required_if:role,emt'
        ];

        if (!$request->has('email') && !$request->has('phone')) {
            return response()->json([
                'errors' => ['contact' => 'Either email or phone is required']
            ], 422);
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone ? $this->formatPhone($request->phone) : null,
            'password' => Hash::make($request->password),
            'role' => $request->role
        ]);

        if ($request->role === 'patient') {

            Patient::create([
                'user_id' => $user->id,
                'blood_group' => $request->blood_group,
                'allergies' => $request->allergies,
                'medical_conditions' => $request->medical_conditions,
                'medications' => $request->medications,
                'emergency_contact_name' => $request->emergency_contact_name,
                'emergency_contact_phone' => $request->emergency_contact_phone
                    ? $this->formatPhone($request->emergency_contact_phone)
                    : null
            ]);

        } elseif ($request->role === 'emt') {

            EmtResponder::create([
                'user_id' => $user->id,
                'license_number' => $request->license_number,
                'qualification_level' => $request->qualification_level,
                'is_available' => true
            ]);
        }

        $token = $user->createToken('auth-token',['*'])->plainTextToken;

        return response()->json([
            'user' => $user->load('patient','emtResponder'),
            'token' => $token,
            'message' => 'Registration successful'
        ],201);
    }


    /*
    |--------------------------------------------------------------------------
    | LOGIN
    |--------------------------------------------------------------------------
    */

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required_without:phone|nullable|email',
            'phone' => 'required_without:email|nullable|string',
            'password' => 'required'
        ]);

        $query = User::query();

        if ($request->email) {
            $query->where('email',$request->email);
        } else {
            $query->where('phone',$this->formatPhone($request->phone));
        }

        $user = $query->first();

        if (!$user || !Hash::check($request->password,$user->password)) {
            throw ValidationException::withMessages([
                'credentials' => ['Invalid credentials']
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Account deactivated'
            ],403);
        }

        $user->update([
            'last_login_at' => now()
        ]);

        $token = $user->createToken('auth-token',['*'])->plainTextToken;

        return response()->json([
            'user' => $user->load('patient','emtResponder'),
            'token' => $token
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CURRENT USER
    |--------------------------------------------------------------------------
    */

    public function me(Request $request)
    {
        return response()->json(
            $request->user()->load('patient','emtResponder')
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FORGOT PASSWORD
    |--------------------------------------------------------------------------
    */

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required_without:phone|email|exists:users,email',
            'phone' => 'required_without:email|string|exists:users,phone'
        ]);

        $user = User::when($request->email,fn($q)=>$q->where('email',$request->email))
                    ->when($request->phone,fn($q)=>$q->where('phone',$request->phone))
                    ->first();

        $otp = str_pad(random_int(0,999999),6,'0',STR_PAD_LEFT);

        PasswordReset::updateOrCreate(
            ['email'=>$user->email],
            [
                'token'=>Str::random(64),
                'otp'=>Hash::make($otp),
                'otp_expires_at'=>Carbon::now()->addMinutes(10),
                'created_at'=>Carbon::now()
            ]
        );

        if ($request->email) {

            Mail::send('emails.reset-otp',
                ['otp'=>$otp,'user'=>$user],
                function($message) use ($user){
                    $message->to($user->email)
                    ->subject('Your Password Reset Code');
                });

        } else {

            $this->sendSMS(
                $user->phone,
                "Your EMS password reset code is: {$otp}"
            );
        }

        return response()->json([
            'message'=>'Verification code sent'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY OTP
    |--------------------------------------------------------------------------
    */

    public function verifyOTP(Request $request)
    {
        $request->validate([
            'email'=>'required_without:phone|email',
            'phone'=>'required_without:email|string',
            'otp'=>'required|string|size:6'
        ]);

        $user = User::when($request->email,fn($q)=>$q->where('email',$request->email))
                    ->when($request->phone,fn($q)=>$q->where('phone',$request->phone))
                    ->first();

        $reset = PasswordReset::where('email',$user->email)->first();

        if(!$reset || !Hash::check($request->otp,$reset->otp)){
            return response()->json([
                'message'=>'Invalid verification code'
            ],400);
        }

        if(Carbon::now()->gt($reset->otp_expires_at)){
            return response()->json([
                'message'=>'Code expired'
            ],400);
        }

        $reset->update([
            'otp_verified_at'=>Carbon::now()
        ]);

        return response()->json([
            'message'=>'OTP verified',
            'token'=>$reset->token
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | RESET PASSWORD
    |--------------------------------------------------------------------------
    */

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'=>'required',
            'password'=>'required|min:8|confirmed'
        ]);

        $reset = PasswordReset::where('token',$request->token)
                              ->whereNotNull('otp_verified_at')
                              ->first();

        if(!$reset){
            return response()->json([
                'message'=>'Invalid reset token'
            ],400);
        }

        $user = User::where('email',$reset->email)->first();

        $user->update([
            'password'=>Hash::make($request->password),
            'password_changed_at'=>Carbon::now()
        ]);

        $user->tokens()->delete();

        $reset->delete();

        return response()->json([
            'message'=>'Password reset successful'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | HELPER FUNCTIONS
    |--------------------------------------------------------------------------
    */

    private function formatPhone($phone)
    {
        $cleaned = preg_replace('/[^\d+]/','',$phone);

        if(str_starts_with($cleaned,'0')){
            $cleaned = '+254'.substr($cleaned,1);
        }

        if(!str_starts_with($cleaned,'+')){
            $cleaned = '+'.$cleaned;
        }

        return $cleaned;
    }

    private function sendSMS($phone,$message)
    {
        // integrate SMS gateway
    }

}