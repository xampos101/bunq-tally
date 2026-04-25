<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Contact::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'phone_number'         => ['required', 'string', 'max:30', 'unique:contacts,phone_number'],
            'whatsapp_profile_pic' => ['sometimes', 'nullable', 'string'],
        ]);

        $contact = Contact::create($data);

        return response()->json($contact, 201);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['sometimes', 'string', 'max:255'],
            'phone_number'         => ['sometimes', 'string', 'max:30', 'unique:contacts,phone_number,' . $contact->id],
            'whatsapp_profile_pic' => ['sometimes', 'nullable', 'string'],
        ]);

        $contact->update($data);

        return response()->json($contact);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();

        return response()->json(null, 204);
    }
}
