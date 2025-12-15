<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }
    public function handleGoogleCallback()
    {
        try {
        
            $googleUser = Socialite::driver('google')->user();
            Log::info('Google User Data: ', (array) $googleUser->user);

            if (!$googleUser || !$googleUser->email) {
                throw new \Exception('Invalid Google user data');
            }

            // if (!str_ends_with($googleUser->email, '@5core.com')) {
            //     return redirect()->route('login')->with('error', 'Only 5core.com users are allowed');
            // }
            $allowedEmail = 'iaminchina2@gmail.com';
            $isAllowedDomain = str_ends_with($googleUser->email, '@5core.com');
            $isAllowedSpecific = $googleUser->email === $allowedEmail;

            if (!($isAllowedDomain || $isAllowedSpecific)) {
                return redirect()->route('login')
                    ->with('error', 'Only 5core.com users and iaminchina2@gmail.com are allowed');
            }
            $user = User::where('email', $googleUser->email)
                        ->orWhere('google_id', $googleUser->id)
                        ->first();

            if ($user) {
                if (empty($user->google_id)) {
                    $user->update(['google_id' => $googleUser->id]);
                }

                Auth::login($user, true);
                return redirect()->route('dashboard');
            }
            $userData = User::create([
                'name'        => $googleUser->name ?? explode('@', $googleUser->email)[0],
                'email'       => $googleUser->email,
                'google_id'   => $googleUser->id,
                'password'    => bcrypt(Str::random(24)),
                'phone_number'=> null,
                'gender'      => null,
                'date_of_birth'=> null,
                'address'     => '',
                'profile_photo'=> $googleUser->avatar ?? null,
                'last_active' => now(),
                'role'        => 'Super Admin',
                'branch_id'   => null,
                'status'      => 1,
            ]);

            Auth::login($userData, true);
            return redirect()->route('dashboard');

        } catch (\Exception $e) {
            Log::error('Google Auth Error: ' . $e->getMessage());
            return redirect()
                ->route('login')
                ->withErrors(['error' => 'Google authentication failed. Please try again.']);
        }
    }

}
