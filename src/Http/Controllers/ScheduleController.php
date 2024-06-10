<?php

namespace RobersonFaria\DatabaseSchedule\Http\Controllers;

use RobersonFaria\DatabaseSchedule\Http\Requests\ScheduleRequest;
use RobersonFaria\DatabaseSchedule\Http\Services\CommandService;
use RobersonFaria\DatabaseSchedule\Models\Schedule;
use RobersonFaria\DatabaseSchedule\View\Helpers;
use \Illuminate\Console\Scheduling\Schedule as BaseSchedule;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;

class ScheduleController extends Controller
{
    /**
     * Display a listing of the schedule.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Http\Response|\Illuminate\View\View
     */
    public function index()
    {
        $schedule = app(config('database-schedule.model'));
        $schedules = $schedule::query();

        $orderBy = request()->input('orderBy')
            ?? session()->get(Schedule::SESSION_KEY_ORDER_BY)
            ?? config('database-schedule.default_ordering', 'created_at');
        $direction = request()->input('direction')
            ?? session()->get(Schedule::SESSION_KEY_DIRECTION)
            ?? config('database-schedule.default_ordering_direction', 'DESC');

        session()->put(Schedule::SESSION_KEY_ORDER_BY, $orderBy);
        session()->put(Schedule::SESSION_KEY_DIRECTION, $direction);

        foreach (session()->get(Schedule::SESSION_KEY_FILTERS, []) as $column => $value) {
            if ($column === 'status') {
                if ($value === Schedule::STATUS_TRASHED) {
                    $schedules->onlyTrashed();
                } else if ($value === Schedule::STATUS_INACTIVE) {
                    $schedules->inactive();
                } else if ($value === Schedule::STATUS_ACTIVE) {
                    $schedules->active();
                }
            } else if ($column === 'command') {
                // also search in job descriptions:
                $schedules->where(function ($query) use ($value) {
                    $query->orWhere('command', 'like', '%' . $value . '%');

                    $commands = (new CommandService())->get()->filter(function ($command) use ($value) {
                        return !empty($value) ? stristr($command->description, $value) : false;
                    })->pluck('name');

                    foreach ($commands as $command) {
                        $query->orWhere('command', 'like', '%' . $command . '%');
                    }
                });
            } else if ($value) {
                $schedules->where($column, 'like', '%' . $value . '%');
            }
        }

        $schedules = $schedules
            ->orderBy($orderBy, $direction)
            ->paginate(config('database-schedule.per_page', 10));

        return view('schedule::index')->with(compact('schedules', 'orderBy', 'direction'));
    }

    public function filter()
    {
        session()->put(Schedule::SESSION_KEY_FILTERS, request()->input('filters'));

        return redirect()->to(Helpers::indexRoute());
    }


    /**
     * Show the form for creating a new schedule.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Http\Response|\Illuminate\View\View
     */
    public function create()
    {
        return view('schedule::create');
    }

    /**
     * Store a newly created schedule.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(ScheduleRequest $request)
    {
        try {
            $schedule = app(config('database-schedule.model'));
            $schedule->create($request->all());

            return redirect()
                ->action('\RobersonFaria\DatabaseSchedule\Http\Controllers\ScheduleController@index')
                ->with('success', trans('schedule::schedule.messages.save-success'));
        } catch (\Exception $e) {
            report($e);
            return back()
                ->with('error', trans('schedule::schedule.messages.save-error'))
                ->withInput();
        }
    }

    /**
     * Display the schedule.
     *
     * @param Schedule $schedule
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Http\Response|\Illuminate\View\View
     */
    public function show(Schedule $schedule)
    {
        $schedule->load(['histories' => function ($query) {
            $query->latest();
        }]);

        return view('schedule::show')
            ->with(compact('schedule'));
    }

    /**
     * Show the form for editing the schedule.
     *
     * @param Schedule $schedule
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Http\Response|\Illuminate\View\View
     */
    public function edit(Schedule $schedule)
    {
        return view('schedule::edit')
            ->with(compact('schedule'));
    }

    /**
     * Update the schedule
     *
     * @param ScheduleRequest $request
     * @param Schedule $schedule
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ScheduleRequest $request, Schedule $schedule)
    {
        try {
            $schedule->update($request->all());

            return redirect()
                ->action('\RobersonFaria\DatabaseSchedule\Http\Controllers\ScheduleController@index')
                ->with('success', trans('schedule::schedule.messages.save-success'));
        } catch (\Exception $e) {
            report($e);
            return back()
                ->with('error', trans('schedule::schedule.messages.save-error'))
                ->withInput();
        }
    }

    public function status(Schedule $schedule, bool $status)
    {
        try {
            $schedule->status = $status;
            $schedule->save();

            return redirect()
                ->action('\RobersonFaria\DatabaseSchedule\Http\Controllers\ScheduleController@index')
                ->with('success', trans('schedule::schedule.messages.save-success'));
        } catch (\Exception $e) {
            report($e);
            return back()
                ->with('error', trans('schedule::schedule.messages.save-error'))
                ->withInput();
        }
    }

    /**
     * Remove the schedule
     *
     * @param Schedule $schedule
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Schedule $schedule)
    {
        try {
            $schedule->delete();

            return redirect()
                ->action('\RobersonFaria\DatabaseSchedule\Http\Controllers\ScheduleController@index')
                ->with('success', trans('schedule::schedule.messages.save-success'));
        } catch (\Exception $e) {
            report($e);
            return back()
                ->with('error', trans('schedule::schedule.messages.save-error'))
                ->withInput();
        }
    }

    /**
     * Restore a thrashed schedule
     *
     * @param Schedule $schedule
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore(Schedule $schedule)
    {
        try {
            $schedule->restore();

            return redirect()
                ->action('\RobersonFaria\DatabaseSchedule\Http\Controllers\ScheduleController@index')
                ->with('success', trans('schedule::schedule.messages.save-success'));
        } catch (\Exception $e) {
            report($e);
            return back()
                ->with('error', trans('schedule::schedule.messages.save-error'))
                ->withInput();
        }
    }

    public function filterReset()
    {
        session()->forget(Schedule::SESSION_KEY_ORDER_BY);
        session()->forget(Schedule::SESSION_KEY_DIRECTION);
        session()->forget(Schedule::SESSION_KEY_FILTERS);

        return redirect()->to(Helpers::indexRoute());
    }

    public function run(Schedule $task, BaseSchedule $schedule) {
        if ($task->command === 'custom') {
            $command = $task->command_custom;
            $event = $schedule->exec($command);
        } else {
            $command = $task->command;
            $event = $schedule->command(
                $command,
                array_values($task->getArguments()) + $task->getOptions()
            );
        }
        $event->run(Container::getInstance());

        // $event->cron($task->expression);

        //ensure output is being captured to write history
        $event->storeOutput();

        if ($task->environments) {
            $event->environments(explode(',', $task->environments));
        }

        if ($task->even_in_maintenance_mode) {
            $event->evenInMaintenanceMode();
        }

        if ($task->without_overlapping) {
            $event->withoutOverlapping();
        }

        if ($task->run_in_background) {
            $event->runInBackground();
        }

        if (!empty($task->webhook_before)) {
            $event->pingBefore($task->webhook_before);
        }

        if (!empty($task->webhook_after)) {
            $event->thenPing($task->webhook_after);
        }

        if (!empty($task->email_output)) {
            if ($task->sendmail_success) {
                $event->emailOutputTo($task->email_output);
            }

            if ($task->sendmail_error) {
                $event->emailOutputOnFailure($task->email_output);
            }
        }

        if (!empty($task->on_one_server)) {
            $event->onOneServer();
        }

        $event->onSuccess(
            function () use ($task, $event, $command) {
                $this->createLogFile($task, $event);
                if ($task->log_success) {
                    $this->createHistoryEntry($task, $event, $command);
                }
            }
        );

        $event->onFailure(
            function () use ($task, $event, $command) {
                $this->createLogFile($task, $event, 'critical');
                if ($task->log_error) {
                    $this->createHistoryEntry($task, $event, $command);
                }
            }
        );

        $event->after(function () use ($event) {
            unlink($event->output);
        });


        return response()->json(Process::$exitCodes[$event->exitCode] ?? 'Unknown error');

        // unset($event);

    }
}
