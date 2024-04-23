<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Requests\Api\User\LoginRequest;
use App\Http\Requests\Api\User\RegisterRequest;
use App\Http\Resources\NormalUsersResource;
use App\Http\Resources\VenueResource;
use App\Http\Resources\FAQResource;
use App\Models\NormalUsers;
use App\Models\Venue;
use App\Models\FAQ;
use App\Models\Category;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends BaseApiController
{
    public function register(RegisterRequest $request)
    {
        try {
            DB::beginTransaction();
            $validated = $request->validated();

            $user = NormalUsers::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phonenumber' => $validated['phonenumber'],
                'address' => $validated['address'],
                'gender' => $validated['gender'],
                'password' => bcrypt($validated['password']),
            ]);

            $tokenResult = $user->createToken('Personal Access Token');
            $token = $tokenResult->token;
            $token->expires_at = Carbon::now()->addMonths(3);
            $token->save();

            if (!$tokenResult) {
                return $this->sendError("Server Error. Please try again later.");
            }

            $token = [
                'access_token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => Carbon::parse(
                    $tokenResult->token->expires_at
                )->toDateTimeString(),
            ];

            DB::commit();

            return $this->sendResponse(['user' => new NormalUsersResource($user), 'token' => $token]);
        } catch (Exception $e) {
            dd($e->getMessage());
            DB::rollBack();
            return $this->sendError("Server Error. Please try again later.");
        }
    }

    public function login(LoginRequest $request)
    {
        try {
            $validate = $request->validated();
            $user = NormalUsers::where('email', $validate['email'])->first();

            if (!$user) {
                return $this->sendError('User not found!');
            }

            if (!Hash::check($request->password, $user->password)) {
                return $this->sendError('The email or password is incorrect.');
            }

            $tokenResult = $user->createToken('Personal Access Token');
            $token = $tokenResult->token;
            $token->expires_at = Carbon::now()->addMonths(3);
            $token->save();

            if (!$tokenResult) {
                return $this->sendError("Server Error. Please try again later.");
            }

            $token = [
                'access_token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => Carbon::parse(
                    $tokenResult->token->expires_at
                )->toDateTimeString(),
            ];

            $message = 'Login successful';

            return $this->sendResponse(['user' => new NormalUsersResource($user), 'token' => $token], $message);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function logout(Request $request)
    {
        try {
            $token = auth('api')->user()->token();
            $token->revoke();
            return $this->sendResponse([], "User logged out successfully.");
        } catch (\Exception $e) {

            return $this->sendError("Server Error. Please try again later.");
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'old_password' => 'required|string|min:8|max:20',
                'new_password' => 'required|string|min:8|max:20',
            ]);

            $user = NormalUsers::findOrfail(auth('api')->user()->id);

            if (!(Hash::check($request['old_password'], $user->getAuthPassword()))) {
                return $this->sendError('Your old password does not match with the password you provided. Please try again.');
            }

            if (strcmp($request['old_password'], $request['new_password']) == 0) {
                return $this->sendError('New Password cannot be same as your old password. Please choose a different password.');
            }

            $user->password = bcrypt($request['new_password']);
            $user->save();

            return $this->sendResponse([
                'user' => new NormalUsersResource($user),
            ], "Password updated successfully");
        } catch (\Exception $e) {
            return $this->sendError("Server Error. Please try again later.");
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            $user = NormalUsers::where('email', $request->email)->first();

            if (!$user) {
                return $this->sendError('User not found with this email.');
            }

            if ($user->otp_sent_at) {
                $diffInSeconds = (int) Carbon::now()->diffInSeconds($user->otp_sent_at);
            }

            if ($user->otp && $diffInSeconds < 1) {
                return $this->sendError("Verification sent already! Please try again in " . 180 - $diffInSeconds . " seconds.");
            }

            $email_verifiaction_code = $this->generateVerificationCode();

            $user->otp = $email_verifiaction_code;
            $user->otp_sent_at = Carbon::now();

            $temp_token = $this->generateTemporaryToken();

            if (!$temp_token) {
                return $this->errorResponse('Sorry! We cannot process your request at this moment. Please contact customer support for more details.');
            }
            $user->temp_token = $temp_token;
            $user->save();

            Mail::to($user->email)->send(new \App\Mail\SendOTP($user));

            return $this->sendResponse([
                'temp_token' => $temp_token,
            ], "OTP has been sent to your email");
        } catch (Exception $e) {
            dd($e->getMessage());
            return $this->sendError("Server Error. Please try again later.");
        }
    }

    private function generateVerificationCode()
    {
        if (config('constant.app_env') === "local")
            return 987654;
        else
            return rand(10000, 99999);
    }

    protected function generateTemporaryToken()
    {
        $temp_token = Str::random(60);

        if (NormalUsers::where('temp_token', $temp_token)->count() == 0) {
            return $temp_token;
        }

        $this->generateTemporaryToken();
    }

    public function forgotOTPVerify(Request $request)
    {
        try {
            $user = NormalUsers::where('temp_token', $request->temp_token)
                ->where('otp', $request->otp)
                ->first();

            if (!$user) {
                return $this->sendError('The email / otp does not match');
            }

            if (((int) Carbon::now()->diffInSeconds($user->otp_sent_at)) > 600) {
                return $this->sendError("OTP Expired");
            }

            $user->otp_verified_at = Carbon::now();
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'OTP verified !!',
            ]);
        } catch (\Exception $e) {
            return $this->sendError("Server Error. Please try again later.");
        }
    }

    public function resetPassword(Request $request)
    {
        try {

            $user = NormalUsers::where('temp_token', $request->temp_token)->where('otp', $request->otp)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please enter the temp_token and otp',
                ]);
            }

            $user->password = bcrypt($request->new_password);
            $user->otp = null;
            $user->temp_token = null;
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Password Reset Successfully',
            ]);
        } catch (\Exception $e) {
            return $this->sendError("Server Error. Please try again later.");
        }
    }

    public function getallvenue()
    {
        try {
            $venues = Venue::all();

            return $this->sendResponse(VenueResource::collection($venues), 'Data fetched successfully!');
        } catch (Exception $e) {
            dd($e->getMessage());
            return $this->sendError('Something went wrong!');
        }
    }

    public function updateprofile(Request $request)
    {
        try {


            $user = NormalUsers::findOrfail(auth('api')->user()->id);
            $user->name = $request->name;
            $user->email = $request->email;
            $user->phonenumber = $request->phonenumber;
            $user->address = $request->address;
            $user->gender = $request->gender;
            $user->save();

            return $this->sendResponse([
                'user' => new NormalUsersResource($user),
            ], "Profile updated successfully");
        } catch (\Exception $e) {
            dd($e->getMessage());
            return $this->sendError("Server Error. Please try again later.");
        }
    }

    public function getallcategory()
    {
        try {
            $category = Category::all();

            return $this->sendResponse(VenueResource::collection($category), 'Data fetched successfully!');
        } catch (Exception $e) {
            dd($e->getMessage());
            return $this->sendError('Something went wrong!');
        }
    }

    public function forfaq()
    {
        try {
            $faq = FAQ::all();

            return $this->sendResponse(FAQResource::collection($faq), 'Data fetched successfully!');
        } catch (Exception $e) {
            dd($e->getMessage());
            return $this->sendError('Something went wrong!');
        }
    }
}
