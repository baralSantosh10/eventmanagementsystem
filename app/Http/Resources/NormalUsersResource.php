<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NormalUsersResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => "$this->id",
            'email' => $this->email,
            'name' => $this->name,
            'phonenumber' => str($this->phonenumber),
            'address' => $this->address,
            'gender' => str($this->gender),
            'status' => $this->status,
        ];
    }
}
