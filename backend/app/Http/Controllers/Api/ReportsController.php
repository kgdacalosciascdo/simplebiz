<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegistryConflictException;
use App\Http\Controllers\Controller;
use App\Services\ReportCompletionService;
use App\Services\ReportsService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly ReportsService $service, private readonly ReportCompletionService $completion) {}

    public function catalog(Request $request)
    {
        [$items, $meta] = $this->service->catalog($this->context->get(), $request);

        return ApiResponse::success($items, 200, $meta);
    }

    public function definition(Request $request, string $key)
    {
        try {
            return ApiResponse::success($this->service->definition($this->context->get(), $request, $key, $request->integer('version') ?: null));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 403, $exception->errors);
        }
    }

    public function generate(Request $request)
    {
        $input = $request->validate(['definition_key' => ['required', 'string', 'max:120'], 'definition_version' => ['nullable', 'integer', 'min:1'], 'parameters' => ['nullable', 'array'], 'output_type' => ['nullable', 'in:display,pdf,xlsx,csv']]);
        try {
            $reportRequest = $this->service->generate($this->context->get(), $request, $input);

            return ApiResponse::success($reportRequest, in_array($reportRequest->status, ['queued', 'processing'], true) ? 202 : 201);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function request(Request $request, string $id)
    {
        return ApiResponse::success($this->service->request($this->context->get(), $request, $id));
    }

    public function cancelRequest(Request $request, string $id)
    {
        try {
            return ApiResponse::success($this->service->cancel($this->context->get(), $request, $id));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function retryRequest(Request $request, string $id)
    {
        try {
            return ApiResponse::success($this->service->retry($this->context->get(), $request, $id));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function output(Request $request, string $id)
    {
        try {
            return ApiResponse::success($this->service->output($this->context->get(), $request, $id));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 403, $exception->errors);
        }
    }

    public function download(Request $request, string $id)
    {
        try {
            return $this->service->download($this->context->get(), $request, $id);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function print(Request $request, string $id)
    {
        try {
            return ApiResponse::success($this->service->print($this->context->get(), $request, $id));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 403, $exception->errors);
        }
    }

    public function drillDown(Request $request, string $id)
    {
        $input = $request->validate(['record_id' => ['required', 'string', 'max:100']]);
        try {
            return ApiResponse::success($this->service->drillDown($this->context->get(), $request, $id, $input));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function snapshot(Request $request, string $id)
    {
        $input = $request->validate(['title' => ['nullable', 'string', 'max:220']]);
        try {
            return ApiResponse::success($this->service->snapshot($this->context->get(), $request, $id, $input['title'] ?? null), 201);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function history(Request $request)
    {
        return ApiResponse::success($this->service->history($this->context->get(), $request));
    }

    public function favorites(Request $request)
    {
        return ApiResponse::success($this->service->favorites($this->context->get(), $request));
    }

    public function favorite(Request $request)
    {
        $input = $request->validate(['definition_key' => ['required', 'string', 'max:120'], 'definition_version' => ['nullable', 'integer', 'min:1'], 'label' => ['nullable', 'string', 'max:180'], 'parameters' => ['nullable', 'array'], 'display_order' => ['nullable', 'integer', 'min:0']]);
        try {
            return ApiResponse::success($this->service->favorite($this->context->get(), $request, $input), 201);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function removeFavorite(Request $request, string $id)
    {
        $this->service->removeFavorite($this->context->get(), $request, $id);

        return ApiResponse::success(null, 204);
    }

    public function savedViews(Request $request)
    {
        return ApiResponse::success($this->service->savedViews($this->context->get(), $request));
    }

    public function saveView(Request $request, ?string $id = null)
    {
        $input = $request->validate(['definition_key' => ['required', 'string', 'max:120'], 'definition_version' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:180'], 'parameters' => ['nullable', 'array'], 'presentation' => ['nullable', 'array']]);
        try {
            return ApiResponse::success($this->service->saveView($this->context->get(), $request, $input, $id), $id ? 200 : 201);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function deleteView(Request $request, string $id)
    {
        $this->service->deleteView($this->context->get(), $request, $id);

        return ApiResponse::success(null, 204);
    }

    public function schedules(Request $request)
    {
        return ApiResponse::success($this->completion->schedules($this->context->get(), $request));
    }

    public function storeSchedule(Request $request)
    {
        $input = $request->validate([
            'definition_key' => ['required', 'string', 'max:120'],
            'definition_version' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:180'],
            'recurrence' => ['nullable', 'in:daily,weekly,monthly'],
            'timezone' => ['nullable', 'timezone'],
            'run_time' => ['nullable', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'parameters' => ['nullable', 'array'],
            'output_format' => ['nullable', 'in:pdf,xlsx,csv,display'],
            'recipient_user_ids' => ['nullable', 'array', 'min:1'],
            'recipient_user_ids.*' => ['integer', 'distinct'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:draft,active'],
        ]);
        try {
            return ApiResponse::success($this->completion->createSchedule($this->context->get(), $request, $input), 201);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function updateSchedule(Request $request, string $id)
    {
        $input = $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:180'],
            'recurrence' => ['sometimes', 'in:daily,weekly,monthly'],
            'timezone' => ['sometimes', 'timezone'],
            'run_time' => ['sometimes', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'parameters' => ['nullable', 'array'],
            'output_format' => ['sometimes', 'in:pdf,xlsx,csv,display'],
            'recipient_user_ids' => ['sometimes', 'array', 'min:1'],
            'recipient_user_ids.*' => ['integer', 'distinct'],
            'ends_at' => ['nullable', 'date'],
        ]);
        try {
            return ApiResponse::success($this->completion->updateSchedule($this->context->get(), $request, $id, $input));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function scheduleAction(Request $request, string $id, string $action)
    {
        try {
            $schedule = $action === 'run'
                ? $this->completion->runSchedule($this->context->get(), $request, $id)
                : $this->completion->changeSchedule($this->context->get(), $request, $id, $action);

            return ApiResponse::success($schedule);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function scheduleOccurrences(Request $request, string $id)
    {
        try {
            return ApiResponse::success($this->completion->occurrences($this->context->get(), $request, $id));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 403, $exception->errors);
        }
    }

    public function deliveries(Request $request)
    {
        return ApiResponse::success($this->completion->deliveries($this->context->get(), $request));
    }

    public function retryDelivery(Request $request, string $id)
    {
        try {
            return ApiResponse::success($this->completion->retryDelivery($this->context->get(), $request, $id));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function compare(Request $request)
    {
        $input = $request->validate([
            'definition_key' => ['required', 'string', 'max:120'],
            'definition_version' => ['nullable', 'integer', 'min:1'],
            'primary_parameters' => ['nullable', 'array'],
            'comparison_parameters' => ['nullable', 'array'],
        ]);
        try {
            return ApiResponse::success($this->completion->compare($this->context->get(), $request, $input));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function analytics(Request $request)
    {
        return ApiResponse::success($this->completion->analytics());
    }

    public function definitions(Request $request)
    {
        return ApiResponse::success($this->completion->governance($request));
    }

    public function supersedeDefinition(Request $request, string $key)
    {
        $input = $request->validate([
            'source_definition_id' => ['nullable', 'uuid'],
            'name' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'business_question' => ['nullable', 'string'],
        ]);
        try {
            return ApiResponse::success($this->completion->supersedeDefinition($this->context->get(), $request, $key, $input), 201);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function publishDefinition(Request $request, string $id)
    {
        $input = $request->validate(['review_notes' => ['nullable', 'string', 'max:2000']]);
        try {
            return ApiResponse::success($this->completion->publishDefinition($this->context->get(), $request, $id, $input));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function deactivateDefinition(Request $request, string $id)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            return ApiResponse::success($this->completion->deactivateDefinition($this->context->get(), $request, $id, $input['reason']));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function packs(Request $request)
    {
        try {
            return ApiResponse::success($this->completion->packs($this->context->get(), $request));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 403, $exception->errors);
        }
    }

    public function storePack(Request $request)
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'pack_key' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'parameters' => ['nullable', 'array'],
        ]);
        try {
            return ApiResponse::success($this->completion->createPack($this->context->get(), $request, $input), 201);
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function addPackItem(Request $request, string $id)
    {
        $input = $request->validate([
            'report_output_id' => ['required', 'uuid'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'label' => ['nullable', 'string', 'max:180'],
        ]);
        try {
            return ApiResponse::success($this->completion->addPackItem($this->context->get(), $request, $id, $input));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function packAction(Request $request, string $id, string $action)
    {
        try {
            return ApiResponse::success($this->completion->transitionPack($this->context->get(), $request, $id, $action));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function purge(Request $request)
    {
        try {
            return ApiResponse::success($this->completion->purgeExpired($this->context->get(), $request));
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }
}
