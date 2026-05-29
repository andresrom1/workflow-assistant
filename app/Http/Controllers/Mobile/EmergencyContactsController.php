<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use App\Models\MobileAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de contactos de emergencia (spec v2 §4.3). Máx 3 por MobileAccount.
 *
 * Persisten en backend (no en el dispositivo) porque el backend necesita
 * dispatchar los WhatsApp al momento del evento.
 */
class EmergencyContactsController extends Controller
{
    /** Regex E.164 — '+' seguido de 1..15 dígitos. */
    private const PHONE_REGEX = '/^\+[1-9]\d{6,14}$/';

    public function index(Request $request): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $contacts = EmergencyContact::where('mobile_account_id', $account->id)
            ->orderBy('id')
            ->get()
            ->map(fn (EmergencyContact $c) => $this->payload($c))
            ->all();

        return response()->json(['data' => $contacts]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $count = EmergencyContact::where('mobile_account_id', $account->id)->count();
        if ($count >= EmergencyContact::MAX_PER_ACCOUNT) {
            throw new ApiException(
                'Ya tenés el máximo de contactos de emergencia ('.EmergencyContact::MAX_PER_ACCOUNT.').',
                'CONTACT_LIMIT_REACHED',
                422,
            );
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:'.self::PHONE_REGEX],
        ]);

        $contact = EmergencyContact::create([
            'mobile_account_id' => $account->id,
            'name' => $data['name'],
            'phone' => $data['phone'],
        ]);

        return response()->json($this->payload($contact), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contact = $this->findOrFail($request, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'required', 'string', 'regex:'.self::PHONE_REGEX],
        ]);

        $contact->fill($data)->save();

        return response()->json($this->payload($contact));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $contact = $this->findOrFail($request, $id);
        $contact->delete();

        return response()->json([], 204);
    }

    private function findOrFail(Request $request, int $id): EmergencyContact
    {
        /** @var MobileAccount $account */
        $account = $request->user();

        $contact = EmergencyContact::where('mobile_account_id', $account->id)
            ->where('id', $id)
            ->first();

        if (! $contact) {
            throw new ApiException('No encontramos ese contacto.', 'CONTACT_NOT_FOUND', 404);
        }

        return $contact;
    }

    /** @return array<string, mixed> */
    private function payload(EmergencyContact $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
        ];
    }
}
