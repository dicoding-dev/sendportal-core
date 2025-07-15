<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Http\Requests\Api\SubscribersSyncRequest;
use Sendportal\Base\Http\Requests\Api\SubscriberStoreRequest;
use Sendportal\Base\Http\Requests\Api\SubscriberUpdateRequest;
use Sendportal\Base\Http\Resources\Subscriber as SubscriberResource;
use Sendportal\Base\Models\Subscriber;
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
        $requestedSubscribers = collect($request->validated('subscribers'));
        $existingSubscribers = DB::table('sendportal_subscribers')
            ->whereIn('email', $requestedSubscribers->pluck('email'))
            ->get(['id', 'email']);

        $toBeUpdatedSubscribers = collect([]);
        $toBeInsertedSubscribers = collect([]);

        foreach ($requestedSubscribers as $subscriber) {
            $existingSubscriber = $existingSubscribers->firstWhere('email', $subscriber['email']);
            if ($existingSubscriber && isset($existingSubscriber->id)) {
                $toBeUpdatedSubscribers->push([
                    'id' => $existingSubscriber->id,
                    'workspace_id' => $workspaceId,
                    'email' => $subscriber['email'],
                    'first_name' => $subscriber['first_name'] ?? '',
                    'last_name' => $subscriber['last_name'] ?? '',
                    'meta' => json_encode($subscriber['meta'] ?? ''),
                ]);
            } else {
                $toBeInsertedSubscribers->push([
                    'workspace_id' => $workspaceId,
                    'email' => $subscriber['email'],
                    'first_name' => $subscriber['first_name'] ?? '',
                    'last_name' => $subscriber['last_name'] ?? '',
                    'meta' => json_encode($subscriber['meta'] ?? ''),
                    'hash' => Uuid::uuid4()->toString(),
                ]);
            }
        }

        $toBeInsertedSubscribers->chunk(100)
            ->each(function ($chunk) {
                DB::table('sendportal_subscribers')->insert($chunk->toArray());
            });

        $toBeUpdatedSubscribers->chunk(100)
            ->each(function (Collection $chunk) use ($workspaceId) {
                if ($chunk->isEmpty()) {
                    return;
                }

                $ids = $chunk->pluck('id')->toArray();
                $connection = DB::connection();

                $cases = [
                    'email' => '',
                    'first_name' => '',
                    'last_name' => '',
                    'meta' => '',
                ];

                foreach ($chunk as $item) {
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

                $idsList = implode(',', array_map(function($id) use ($connection) {
                    return $connection->escape($id);
                }, $ids));

                $query = "UPDATE sendportal_subscribers SET ";
                $query .= "workspace_id = {$workspaceId}, ";
                $query .= "email = CASE id {$cases['email']} END, ";
                $query .= "first_name = CASE id {$cases['first_name']} END, ";
                $query .= "last_name = CASE id {$cases['last_name']} END, ";
                $query .= "meta = CASE id {$cases['meta']} END ";
                $query .= "WHERE id IN ({$idsList})";

                DB::statement($query);
            });

        // Fetch all subscribers that were just inserted or updated
        $emails = $requestedSubscribers->pluck('email')->toArray();
        $subscribers = Subscriber::whereIn('email', $emails)->orderBy('id')->get();

        return SubscriberResource::collection($subscribers);
    }
}
