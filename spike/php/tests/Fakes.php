<?php

declare(strict_types=1);

namespace AtLocal\Spike\Tests;

use AtLocal\Spike\Http\HttpClient;
use AtLocal\Spike\Http\HttpResponse;

/** Records requests and answers from a callback. */
final class FakeHttp implements HttpClient
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>}> */
    public array $requests = [];

    /** @param callable(string, string, ?array): HttpResponse $handler */
    public function __construct(private $handler)
    {
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'body', 'headers');
        return ($this->handler)($method, $url, $body === null ? null : json_decode($body, true));
    }

    /** @return list<array{0: string, 1: array, 2: string}> all JMAP method calls sent */
    public function methodCalls(): array
    {
        $calls = [];
        foreach ($this->requests as $r) {
            $decoded = $r['body'] === null ? null : json_decode($r['body'], true);
            foreach ($decoded['methodCalls'] ?? [] as $call) {
                $calls[] = $call;
            }
        }
        return $calls;
    }
}

function session(string $accountId = 'acctA', string $apiUrl = 'https://mail.companyname.local:8443/jmap/'): array
{
    return [
        'capabilities' => ['urn:ietf:params:jmap:core' => [], 'urn:ietf:params:jmap:mail' => []],
        'primaryAccounts' => ['urn:ietf:params:jmap:mail' => $accountId, 'urn:ietf:params:jmap:submission' => $accountId],
        'apiUrl' => $apiUrl,
        'downloadUrl' => 'https://mail.companyname.local:8443/jmap/download/{accountId}/{blobId}/{name}?accept={type}',
    ];
}

function json(int $status, mixed $data): HttpResponse
{
    return new HttpResponse($status, json_encode($data));
}

/**
 * In-memory model of the Stalwart management objects the provisioner touches.
 * Implements the documented RFC 8620 Foo/query|get|set semantics only.
 */
final class FakeStalwart
{
    /** @var array<string, array<string, array>> type => id => object */
    public array $objects = [];
    /** @var array<string, array> singleton type => object */
    public array $singletons = [];
    private int $seq = 0;

    public function __construct()
    {
        $this->objects['x:Application'] = ['app1' => ['id' => 'app1', 'description' => 'WebUI', 'resourceUrl' => 'https://github.com/stalwartlabs/webui/releases/download/v2/webui.zip', 'enable' => true]];
    }

    public function handle(string $method, string $url, ?array $body): HttpResponse
    {
        if (str_ends_with($url, '/jmap/session')) {
            return json(200, session('admin'));
        }
        $responses = [];
        foreach ($body['methodCalls'] as [$name, $args, $callId]) {
            [$type, $op] = explode('/', $name);
            $responses[] = [$name, $this->$op($type, $args), $callId];
        }
        return json(200, ['methodResponses' => $responses]);
    }

    private function query(string $type, array $args): array
    {
        $ids = [];
        foreach ($this->objects[$type] ?? [] as $id => $obj) {
            $match = true;
            foreach ($args['filter'] ?? [] as $k => $v) {
                $match = $match && (($obj[$k] ?? null) === $v);
            }
            if ($match) {
                $ids[] = $id;
            }
        }
        return ['ids' => $ids];
    }

    private function get(string $type, array $args): array
    {
        if (($args['ids'] ?? null) === ['singleton']) {
            return ['list' => [$this->singletons[$type] ?? []]];
        }
        return ['list' => array_values(array_intersect_key($this->objects[$type] ?? [], array_flip($args['ids'])))];
    }

    private function set(string $type, array $args): array
    {
        $out = [];
        foreach ($args['create'] ?? [] as $cid => $obj) {
            $id = 'id' . (++$this->seq);
            $this->objects[$type][$id] = ['id' => $id] + $obj;
            $out['created'][$cid] = ['id' => $id];
        }
        foreach ($args['update'] ?? [] as $id => $patch) {
            if ($id === 'singleton') {
                $this->singletons[$type] = $patch + ($this->singletons[$type] ?? []);
            } elseif (isset($this->objects[$type][$id])) {
                $this->objects[$type][$id] = $patch + $this->objects[$type][$id];
            } else {
                $out['notUpdated'][$id] = ['type' => 'notFound'];
                continue;
            }
            $out['updated'][$id] = null;
        }
        foreach ($args['destroy'] ?? [] as $id) {
            unset($this->objects[$type][$id]);
            $out['destroyed'][] = $id;
        }
        return $out;
    }
}
