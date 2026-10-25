<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Reservation;
use App\Support\Pagination;
use App\Support\ReservationAccess;
use App\Support\UserContext;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Read side: the list and the prescription image. */
class ReservationQueryService
{
    private const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    /**
     * A customer sees their own reservations, staff those of their branch, the CEO all (optionally one branch).
     * Items carry no prescription path and no OCR text.
     *
     * @param array{status?: ?string, page?: ?int, per_page?: ?int, branch_id?: ?int} $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(UserContext $user, array $filters): array
    {
        $query = Reservation::query()
            ->select('reservations.*')
            ->selectRaw('(select count(*) from reservation_items where reservation_items.reservation_id = reservations.id) as item_count')
            ->selectRaw('(select coalesce(sum(unit_price * quantity), 0) from reservation_items where reservation_items.reservation_id = reservations.id) as total_price');

        if ($user->role === 'customer') {
            $query->where('user_id', $user->id);
        } elseif ($user->hasRole(ReservationAccess::STAFF)) {
            if ($user->branchId === null) {
                throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
            }
            $query->where('branch_id', $user->branchId); // a branch_id in the query string is ignored for staff
        } elseif ($user->role === 'ceo') {
            if (! empty($filters['branch_id'])) {
                $query->where('branch_id', (int) $filters['branch_id']);
            }
        } else {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $page->getCollection()->map(fn (Reservation $r) => [
                'id' => $r->id,
                'claim_code' => $r->claim_code,
                'status' => $r->status,
                'branch_id' => $r->branch_id,
                'expires_at' => $r->expires_at->toIso8601String(),
                'created_at' => $r->created_at->toIso8601String(),
                'item_count' => (int) $r->item_count,
                'total_price' => number_format((float) $r->total_price, 2, '.', ''),
            ])->values()->all(),
            'meta' => Pagination::meta($page),
        ];
    }

    /**
     * The private image: owner, staff of the same branch and the CEO. Unknown id or no file is 404; anyone else 403.
     * The type comes from the file's content, never from its stored name.
     *
     * @return array{content: string, mime: string, extension: string}
     */
    public function prescription(UserContext $user, int $id): array
    {
        $reservation = Reservation::find($id) ?? throw $this->notFound();

        if (! ReservationAccess::canView($user, $reservation)) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        $path = $reservation->prescription_path;
        $disk = Storage::disk(config('reservation.files_disk'));

        try {
            $content = ($path !== null && $disk->exists($path)) ? $disk->get($path) : null;
        } catch (Throwable) {
            $content = null;
        }
        if (! is_string($content) || $content === '') {
            throw $this->notFound();
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (! isset(self::IMAGE_TYPES[$mime])) {
            throw $this->notFound(); // never stream something that is not a png or jpeg
        }

        return ['content' => $content, 'mime' => $mime, 'extension' => self::IMAGE_TYPES[$mime]];
    }

    private function notFound(): ApiException
    {
        return new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
    }
}
