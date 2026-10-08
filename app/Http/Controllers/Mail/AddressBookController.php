<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreAddressBookContactRequest;
use App\Services\Mail\AddressBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Адресная книга почты (resources/js/address-book.js): подсказки в полях
 * адресатов, вкладки окна выбора и личные контакты пользователя.
 */
class AddressBookController extends Controller
{
    public function __construct(private readonly AddressBookService $book) {}

    public function suggest(Request $request): JsonResponse
    {
        $term = mb_substr((string) $request->query('q', ''), 0, 100);

        return response()->json(['items' => $this->book->suggest($request->user(), $term)]);
    }

    public function browse(Request $request): JsonResponse
    {
        $source = (string) $request->query('source', 'recent');
        if (! in_array($source, AddressBookService::SOURCES, true)) {
            $source = 'recent';
        }
        $term = mb_substr((string) $request->query('q', ''), 0, 100);

        return response()->json(['items' => $this->book->browse($request->user(), $source, $term)]);
    }

    public function store(StoreAddressBookContactRequest $request): JsonResponse
    {
        $data = $request->validated();
        $contact = $this->book->saveContact($request->user(), $data['email'], $data['name'] ?? null, $data['organization'] ?? null);

        return response()->json(['id' => $contact->id]);
    }

    public function destroy(Request $request, int $contact): JsonResponse
    {
        $this->book->deleteContact($request->user(), $contact);

        return response()->json(['ok' => true]);
    }
}
