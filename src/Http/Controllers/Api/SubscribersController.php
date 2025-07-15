<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Ramsey\Uuid\Uuid;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Http\Requests\Api\SubscribersSyncRequest;
use Sendportal\Base\Http\Requests\Api\SubscriberStoreRequest;
use Sendportal\Base\Http\Requests\Api\SubscriberUpdateRequest;
use Sendportal\Base\Http\Resources\Subscriber as SubscriberResource;
use Sendportal\Base\Repositories\Subscribers\SubscriberTenantRepositoryInterface;
use Sendportal\Base\Services\Subscribers\ApiSubscriberService;

class SubscribersController extends Controller
{
    /** @var SubscriberTenantRepositoryInterface */
    protected $subscribers;

    /** @var ApiSubscriberService */
    protected $apiService;

    public function __construct(
        SubscriberTenantRepositoryInterface $subscribers,
        ApiSubscriberService $apiService
    ) {
        $this->subscribers = $subscribers;
        $this->apiService = $apiService;
    }

    /**
     * @throws Exception
     */
    public function index(): AnonymousResourceCollection
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $subscribers = $this->subscribers->paginate($workspaceId, 'last_name');

        return SubscriberResource::collection($subscribers);
    }

    /**
     * @throws Exception
     */
    public function store(SubscriberStoreRequest $request): SubscriberResource
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $subscriber = $this->apiService->storeOrUpdate($workspaceId, collect($request->validated()));

        $subscriber->load('tags');

        return new SubscriberResource($subscriber);
    }

    /**
     * @throws Exception
     */
    public function show(int $id): SubscriberResource
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        return new SubscriberResource($this->subscribers->find($workspaceId, $id, ['tags']));
    }

    /**
     * @throws Exception
     */
    public function update(SubscriberUpdateRequest $request, int $id): SubscriberResource
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $subscriber = $this->subscribers->update($workspaceId, $id, $request->validated());

        return new SubscriberResource($subscriber);
    }

    /**
     * @throws Exception
     */
    public function destroy(int $id): Response
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $this->apiService->delete($workspaceId, $this->subscribers->find($workspaceId, $id));

        return response(null, 204);
    }

    /**
     * @throws Exception
     */
    public function sync(SubscribersSyncRequest $request): AnonymousResourceCollection
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        // Convert to LazyCollection to process items one at a time
        $requestedSubscribers = LazyCollection::make($request->validated('subscribers'));

        // Get all emails first to fetch existing subscribers
        $emails = $requestedSubscribers->pluck('email')->all();
        $existingSubscribers = DB::table('sendportal_subscribers')
            ->whereIn('email', $emails)
            ->get(['id', 'email']);

        // Create index for faster lookups
        $existingSubscribersIndex = $existingSubscribers->keyBy('email');

        // Process subscribers in chunks to reduce memory usage
        $requestedSubscribers->chunk(50)->each(function ($chunk) use ($workspaceId, $existingSubscribersIndex) {
            $toBeUpdated = [];
            $toBeInserted = [];

            foreach ($chunk as $subscriber) {
                $existingSubscriber = $existingSubscribersIndex->get($subscriber['email']);

                if ($existingSubscriber && isset($existingSubscriber->id)) {
                    $toBeUpdated[] = [
                        'id' => $existingSubscriber->id,
                        'workspace_id' => $workspaceId,
                        'email' => $subscriber['email'],
                        'first_name' => $subscriber['first_name'] ?? '',
                        'last_name' => $subscriber['last_name'] ?? '',
                        'meta' => json_encode($subscriber['meta'] ?? ''),
                    ];
                } else {
                    $toBeInserted[] = [
                        'workspace_id' => $workspaceId,
                        'email' => $subscriber['email'],
                        'first_name' => $subscriber['first_name'] ?? '',
                        'last_name' => $subscriber['last_name'] ?? '',
                        'meta' => json_encode($subscriber['meta'] ?? ''),
                        'hash' => Uuid::uuid4()->toString(),
                    ];
                }
            }

            // Insert new subscribers
            if (!empty($toBeInserted)) {
                DB::table('sendportal_subscribers')->insert($toBeInserted);
            }

            // Update existing subscribers
            $this->updateSubscribers(collect($toBeUpdated), $workspaceId);
        });

        unset($requestedSubscribers, $existingSubscribers, $existingSubscribersIndex);

        // Fetch all subscribers that were just inserted or updated
        $subscribers = DB::table('sendportal_subscribers')
            ->whereIn('email', $emails)
            ->orderBy('id')
            ->get(['id', 'email', 'first_name', 'last_name']);

        return SubscriberResource::collection($subscribers);
    }

    /**
     * Update subscribers in the database
     */
    private function updateSubscribers(Collection $subscribers, int $workspaceId): void
    {
        if ($subscribers->isEmpty()) {
            return;
        }

        // No need to chunk as this method is called with data already chunked in the sync method (line 116)
        $ids = $subscribers->pluck('id')->toArray();
        $connection = DB::connection();

        $cases = [
            'email' => '',
            'first_name' => '',
            'last_name' => '',
            'meta' => '',
        ];

        foreach ($subscribers as $item) {
            $id = $connection->escape($item['id']);
            $email = $connection->escape($item['email']);

            $cases['email'] .= "WHEN {$id} THEN {$email} ";

            if (empty($item['first_name'])) {
                $cases['first_name'] .= "WHEN {$id} THEN NULL ";
            } else {
                $firstName = $connection->escape($item['first_name']);
                $cases['first_name'] .= "WHEN {$id} THEN {$firstName} ";
            }

            if (empty($item['last_name'])) {
                $cases['last_name'] .= "WHEN {$id} THEN NULL ";
            } else {
                $lastName = $connection->escape($item['last_name']);
                $cases['last_name'] .= "WHEN {$id} THEN {$lastName} ";
            }

            $meta = $connection->escape($item['meta']);
            $cases['meta'] .= "WHEN {$id} THEN {$meta} ";
        }

        $idsList = implode(',', array_map(static fn ($id) => $connection->escape($id), $ids));

        $query = "UPDATE sendportal_subscribers SET ";
        $query .= "workspace_id = {$workspaceId}, ";
        $query .= "email = CASE id {$cases['email']} END, ";
        $query .= "first_name = CASE id {$cases['first_name']} END, ";
        $query .= "last_name = CASE id {$cases['last_name']} END, ";
        $query .= "meta = CASE id {$cases['meta']} END ";
        $query .= "WHERE id IN ({$idsList})";

        DB::statement($query);
    }
}
