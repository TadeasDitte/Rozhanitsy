<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class AuthentikController extends Controller
{
    /**
     * Redirect the user to Authentik for authentication.
     */
    public function redirect(): RedirectResponse|SymfonyRedirectResponse
    {
        return Socialite::driver('authentik')->redirect();
    }

    /**
     * Handle the callback from Authentik and log the user in.
     */
    public function callback(): RedirectResponse
    {
        try {
            $authentikUser = Socialite::driver('authentik')->user();
        } catch (Throwable) {
            return to_route('login')->with('status', __('Authentik sign-in was cancelled or failed.'));
        }

        Auth::login($this->findOrCreateUser($authentikUser));

        return to_route('dashboard');
    }

    /**
     * Find the local user linked to the Authentik account, linking by email
     * on first login and creating a new user if none exists yet.
     */
    protected function findOrCreateUser(SocialiteUser $authentikUser): User
    {
        $user = User::query()->where('authentik_id', $authentikUser->getId())->first();

        if ($user) {
            return $user;
        }

        $user = User::query()->where('email', $authentikUser->getEmail())->first();

        if ($user) {
            $user->update(['authentik_id' => $authentikUser->getId()]);

            return $user;
        }

        $user = User::query()->create([
            'name' => $authentikUser->getName() ?? $authentikUser->getNickname() ?? $authentikUser->getEmail(),
            'email' => $authentikUser->getEmail(),
            'authentik_id' => $authentikUser->getId(),
            'password' => Hash::make(Str::random(40)),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
