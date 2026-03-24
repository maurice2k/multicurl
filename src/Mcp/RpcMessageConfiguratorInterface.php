<?php
declare(strict_types = 1);

namespace Maurice\Multicurl\Mcp;

/**
 * Adjusts each outbound {@see RpcMessage} immediately before it is sent (for example JSON-RPC `_meta`).
 *
 * {@see \Maurice\Multicurl\McpChannel} invokes this when a configurator is supplied; see that class for how wiring works.
 */
interface RpcMessageConfiguratorInterface
{
    public function configure(RpcMessage $message): void;
}
