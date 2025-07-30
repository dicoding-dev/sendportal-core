<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Http\Requests\Api\CampaignStoreRequest;
use Sendportal\Base\Http\Resources\Campaign as CampaignResource;
use Sendportal\Base\Http\Resources\CampaignStat;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Repositories\Campaigns\CampaignTenantRepositoryInterface;

class CampaignsController extends Controller
{
    /** @var CampaignTenantRepositoryInterface */
    private $campaigns;

    public function __construct(CampaignTenantRepositoryInterface $campaigns)
    {
        $this->campaigns = $campaigns;
    }

    /**
     * @throws Exception
     */
    public function index(): AnonymousResourceCollection
    {
        $workspaceId = Sendportal::currentWorkspaceId();

        return CampaignResource::collection(
            $this->campaigns->paginate(
                $workspaceId,
                'id',
                ['tags'],
                parameters: [
                    'name' => request('name'),
                ]
            )
        );
    }

    /**
     * @throws Exception
     */
    public function store(CampaignStoreRequest $request): CampaignResource
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $data = Arr::except($request->validated(), ['tags']);

        $data['save_as_draft'] = $request->get('save_as_draft') ?? 0;

        $campaign = $this->campaigns->store($workspaceId, $data);

        $campaign->tags()->sync($request->get('tags'));

        return new CampaignResource($campaign);
    }

    /**
     * @throws Exception
     */
    public function show(int $id): CampaignResource
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $campaign = $this->campaigns->find($workspaceId, $id);

        return new CampaignResource($campaign);
    }

    /**
     * @throws Exception
     */
    public function update(CampaignStoreRequest $request, int $id): CampaignResource
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $data = Arr::except($request->validated(), ['tags']);

        $data['save_as_draft'] = $request->get('save_as_draft') ?? 0;

        $campaign = $this->campaigns->update($workspaceId, $id, $data);

        $campaign->tags()->sync($request->get('tags'));

        return new CampaignResource($campaign);
    }

    /**
     * @throws Exception
     */
    public function stats(): AnonymousResourceCollection
    {
        $messageBaseQuery = Message::query()
            ->selectRaw('count(id)')
            ->where('source_type', '=', Campaign::class)
            ->whereColumn('source_id','=', 'sendportal_campaigns.id');

        $campaigns = $this->campaigns->getQueryBuilder(Sendportal::currentWorkspaceId())
            ->toBase()
            ->join(
                'sendportal_campaign_statuses',
                'sendportal_campaigns.status_id',
                '=',
                'sendportal_campaign_statuses.id'
            )
            ->select([
                'sendportal_campaigns.id',
                'sendportal_campaigns.name',
                'sendportal_campaigns.status_id',
                'sendportal_campaigns.from_name',
                'sendportal_campaigns.from_email',
                'sendportal_campaigns.scheduled_at',
                'sendportal_campaigns.created_at',
                'sendportal_campaigns.updated_at',
                'sendportal_campaign_statuses.name as status_text',
                'sent_count' => $messageBaseQuery->clone()->whereNotNull('sent_at'),
                'bounced_count' => $messageBaseQuery->clone()->whereNotNull('bounced_at'),
                'open_count' => $messageBaseQuery->clone()->whereNotNull('opened_at'),
                'click_count' => $messageBaseQuery->clone()->whereNotNull('clicked_at'),
            ])
            ->orderBy('sendportal_campaigns.id', 'desc');

        return CampaignStat::collection($campaigns->paginate(25));
    }
}
