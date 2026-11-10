<?php

namespace ErnestDefoe\Predictions\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Score;
use ErnestDefoe\Predictions\Scorer;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class PredictionTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-predictions');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'lee', 'email' => 'lee@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'sam', 'email' => 'sam@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 5, 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function call(string $method, string $path, int $as, array $body = []): array
    {
        $options = ['authenticatedAs' => $as];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $response = $this->send($this->request($method, $path, $options)->withQueryParams($params));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return array<string, mixed> */
    private function create(array $body = [], int $as = 2): array
    {
        [$status, $json] = $this->call('POST', '/api/predictions', $as, $body + [
            'type' => 'choice',
            'title' => 'Who wins the Heisman?',
            'options' => ['Arch', 'Jeremiah', 'Julian'],
            'lockAt' => Carbon::now()->addDay()->toIso8601String(),
        ]);
        $this->assertSame(201, $status, json_encode($json));

        return $json['data'];
    }

    private function expire(int $id): void
    {
        Prediction::query()->whereKey($id)->update(['lock_at' => Carbon::now()->subMinute()]);
    }

    #[Test]
    public function a_member_opens_a_prediction_and_others_pick_until_the_lock()
    {
        $p = $this->create();
        [$a, $b] = [$p['options'][0]['id'], $p['options'][1]['id']];

        $this->assertSame(200, $this->call('POST', "/api/predictions/{$p['id']}/pick", 3, ['optionId' => $a])[0]);
        [, $json] = $this->call('POST', "/api/predictions/{$p['id']}/pick", 4, ['optionId' => $b]);
        $this->assertSame(2, $json['data']['picksCount']);
        $this->assertSame(['optionId' => $b, 'value' => null, 'hit' => null, 'points' => null], $json['data']['myPick']);

        // Changing a pick keeps one per member.
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 4, ['optionId' => $a]);
        $this->assertSame(2, Pick::query()->count());

        $this->expire($p['id']);
        [$status, $json] = $this->call('POST', "/api/predictions/{$p['id']}/pick", 5, ['optionId' => $a]);
        $this->assertSame(422, $status);
        $this->assertSame('predictions_locked', $json['errors'][0]['code']);
        $this->assertSame(2, Pick::query()->count());
    }

    #[Test]
    public function hidden_picks_stay_hidden_until_the_lock()
    {
        $p = $this->create(['hidePicks' => true]);
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 3, ['optionId' => $p['options'][0]['id']]);

        [, $json] = $this->call('GET', "/api/predictions/{$p['id']}", 4);
        $this->assertNull($json['data']['options'][0]['count']);
        $this->assertSame(1, $json['data']['picksCount']);
        $this->assertTrue($this->call('GET', "/api/predictions/{$p['id']}/picks", 4)[1]['hidden']);

        $this->expire($p['id']);
        [, $json] = $this->call('GET', "/api/predictions/{$p['id']}", 4);
        $this->assertSame(1, $json['data']['options'][0]['count']);
        $this->assertSame('dana', $this->call('GET', "/api/predictions/{$p['id']}/picks", 4)[1]['data'][0]['user']['username']);
    }

    #[Test]
    public function settling_scores_everyone_notifies_them_and_is_idempotent()
    {
        $p = $this->create();
        [$a, $b] = [$p['options'][0]['id'], $p['options'][1]['id']];
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 3, ['optionId' => $a]);
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 4, ['optionId' => $b]);
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 5, ['optionId' => $b]);

        // Others can't settle; the creator can, even before the lock (it locks then).
        $this->assertSame(403, $this->call('POST', "/api/predictions/{$p['id']}/settle", 3, ['optionId' => $a])[0]);
        [$status, $json] = $this->call('POST', "/api/predictions/{$p['id']}/settle", 2, ['optionId' => $a]);
        $this->assertSame(200, $status);
        $this->assertSame('settled', $json['data']['status']);

        // Bold call: 1 of 3 saw it coming → 10 + round(10 × 2/3) = 17.
        $dana = Pick::query()->where('user_id', 3)->first();
        $this->assertTrue($dana->hit);
        $this->assertSame(17, $dana->points);
        $this->assertFalse(Pick::query()->where('user_id', 4)->first()->hit);

        $this->assertSame(['predictionCalled'], $this->database()->table('notifications')->where('user_id', 3)->pluck('type')->all());
        $this->assertSame(['predictionMissed'], $this->database()->table('notifications')->where('user_id', 4)->pluck('type')->all());

        // Running the scorer again changes nothing.
        $this->app()->getContainer()->make(Scorer::class)->score($p['id']);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->count());
        $this->assertSame(17, (int) Score::query()->where('user_id', 3)->value('points'));

        // A correction moves the alert and the points.
        $this->call('POST', "/api/predictions/{$p['id']}/settle", 2, ['optionId' => $b]);
        $this->assertSame(0, (int) Score::query()->where('user_id', 3)->value('points'));
        $this->assertSame(13, (int) Score::query()->where('user_id', 4)->value('points'));
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 4)->where('type', 'predictionCalled')->where('is_deleted', 0)->count());
        $this->assertSame(0, $this->database()->table('notifications')->where('user_id', 3)->where('type', 'predictionCalled')->where('is_deleted', 0)->count());

        // No picks after it's settled.
        $this->assertSame(422, $this->call('DELETE', "/api/predictions/{$p['id']}/pick", 3)[0]);
    }

    #[Test]
    public function a_number_is_scored_by_closeness()
    {
        $p = $this->create(['type' => 'number', 'title' => 'Total points in the title game?', 'unit' => 'points', 'tolerance' => 5, 'options' => null]);
        foreach ([3 => 50, 4 => 41, 5 => 60] as $user => $value) {
            $this->call('POST', "/api/predictions/{$p['id']}/pick", $user, ['value' => $value]);
        }

        [, $json] = $this->call('POST', "/api/predictions/{$p['id']}/settle", 2, ['value' => 44]);
        $this->assertSame(44.0, (float) $json['data']['answer']);
        $this->assertSame([4, 3], array_map(fn ($c) => $c['user']['id'], array_slice($json['data']['stats']['closest'], 0, 2)));

        $points = Pick::query()->orderBy('user_id')->pluck('points', 'user_id')->all();
        $this->assertSame([3 => 0, 4 => 20, 5 => 0], array_map('intval', $points));
    }

    #[Test]
    public function voiding_takes_the_points_back()
    {
        $p = $this->create(['type' => 'yesno', 'title' => 'Will Flarum 2.1 ship by Dec 15?', 'options' => null]);
        $this->assertSame(['Yes', 'No'], array_column($p['options'], 'label'));
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 3, ['optionId' => $p['options'][0]['id']]);
        $this->call('POST', "/api/predictions/{$p['id']}/settle", 2, ['optionId' => $p['options'][0]['id']]);
        $this->assertSame(1, Score::query()->where('user_id', 3)->count());

        [, $json] = $this->call('POST', "/api/predictions/{$p['id']}/settle", 2, ['void' => true]);
        $this->assertSame('void', $json['data']['status']);
        $this->assertSame(0, Score::query()->where('user_id', 3)->count());
        $this->assertSame(0, $this->database()->table('notifications')->where('user_id', 3)->where('is_deleted', 0)->count());
    }

    #[Test]
    public function the_scheduler_locks_once_and_catches_up_scoring()
    {
        $p = $this->create();
        $this->expire($p['id']);

        $this->app();
        $tick = fn () => $this->app()->getContainer()->make(\ErnestDefoe\Predictions\Console\TickCommand::class)
            ->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
        $tick();
        $tick();
        $this->assertNotNull(Prediction::query()->find($p['id'])->locked_at);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 2)->where('type', 'predictionLocked')->count());

        // A settle whose job never ran: the tick scores it.
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 3, ['optionId' => $p['options'][0]['id']]); // refused, it's locked
        Prediction::query()->whereKey($p['id'])->update(['settled_at' => Carbon::now(), 'correct_option_id' => $p['options'][0]['id'], 'season_id' => 1]);
        $tick();
        $this->assertNotNull(Prediction::query()->find($p['id'])->scored_at);
    }

    #[Test]
    public function the_leaderboard_ranks_by_points_and_a_new_season_names_the_oracles()
    {
        foreach ([3, 4] as $winner) {
            $p = $this->create(['title' => "Question for {$winner}"]);
            $this->call('POST', "/api/predictions/{$p['id']}/pick", 3, ['optionId' => $p['options'][0]['id']]);
            $this->call('POST', "/api/predictions/{$p['id']}/pick", 4, ['optionId' => $p['options'][$winner === 4 ? 0 : 1]['id']]);
            $this->call('POST', "/api/predictions/{$p['id']}/settle", 2, ['optionId' => $p['options'][0]['id']]);
        }

        [, $board] = $this->call('GET', '/api/prediction-leaderboard', 3);
        $this->assertSame(['dana', 'lee'], array_map(fn ($r) => $r['user']['username'], $board['rows']));
        $this->assertSame(1, $board['me']['rank']);
        $this->assertSame(1.0, (float) $board['me']['hitRate']);
        $this->assertSame(2, $board['me']['streak']);

        $this->assertSame(403, $this->call('POST', '/api/prediction-seasons', 3, ['name' => 'Bowl season'])[0]);
        [$status, $json] = $this->call('POST', '/api/prediction-seasons', 1, ['name' => 'Bowl season']);
        $this->assertSame(201, $status);
        $this->assertSame(2, $json['oracles']);

        [, $board] = $this->call('GET', '/api/prediction-leaderboard', 3);
        $this->assertSame('Bowl season', $board['season']['name']);
        $this->assertSame([], $board['rows']);
        [, $old] = $this->call('GET', '/api/prediction-leaderboard?season=1', 3);
        $this->assertSame('dana', $old['oracles'][0]['user']['username']);
        [, $all] = $this->call('GET', '/api/prediction-leaderboard?season=all', 3);
        $this->assertSame(2, count($all['rows']));
    }

    #[Test]
    public function members_cannot_edit_or_delete_others_predictions_and_guests_cannot_pick()
    {
        $p = $this->create();
        $this->assertSame(403, $this->call('PATCH', "/api/predictions/{$p['id']}", 3, ['title' => 'Hijacked'])[0]);
        $this->assertSame(403, $this->call('DELETE', "/api/predictions/{$p['id']}", 3)[0]);

        $response = $this->send($this->request('POST', "/api/predictions/{$p['id']}/pick", ['json' => ['optionId' => $p['options'][0]['id']]]));
        $this->assertContains($response->getStatusCode(), [400, 401, 403]);

        // The choices can change only until someone else picks.
        $this->assertSame(200, $this->call('PATCH', "/api/predictions/{$p['id']}", 2, ['options' => ['A', 'B']])[0]);
        $options = $this->call('GET', "/api/predictions/{$p['id']}", 2)[1]['data']['options'];
        $this->call('POST', "/api/predictions/{$p['id']}/pick", 3, ['optionId' => $options[0]['id']]);
        $this->assertSame('predictions_has_picks', $this->call('PATCH', "/api/predictions/{$p['id']}", 2, ['options' => ['C', 'D']])[1]['errors'][0]['code']);
        $this->assertSame(403, $this->call('DELETE', "/api/predictions/{$p['id']}", 2)[0]);
        $this->assertSame(200, $this->call('PATCH', "/api/predictions/{$p['id']}", 2, ['title' => 'Who wins it all?'])[0]);
    }

    #[Test]
    public function bad_input_is_refused()
    {
        foreach ([
            ['title' => 'x'],
            ['options' => ['Only one']],
            ['lockAt' => Carbon::now()->subHour()->toIso8601String()],
            ['type' => 'dice'],
        ] as $bad) {
            [$status] = $this->call('POST', '/api/predictions', 2, $bad + [
                'type' => 'choice', 'title' => 'Fine title', 'options' => ['A', 'B'], 'lockAt' => Carbon::now()->addDay()->toIso8601String(),
            ]);
            $this->assertSame(422, $status, json_encode($bad));
        }
        $this->assertSame(0, Prediction::query()->count());
    }
}
