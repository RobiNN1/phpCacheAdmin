local key = KEYS[1]
local ttl = redis.call("TTL", key)
local key_type = redis.call("TYPE", key)["ok"]

local ok, mem = pcall(function()
    return redis.call("MEMORY", "USAGE", key)
end)
local memory_usage = ok and mem or 0

local count

if key_type == "set" then
    count = redis.call("SCARD", key)
elseif key_type == "list" then
    count = redis.call("LLEN", key)
elseif key_type == "zset" then
    count = redis.call("ZCARD", key)
elseif key_type == "hash" then
    count = redis.call("HLEN", key)
elseif key_type == "stream" then
    count = redis.call("XLEN", key)
end

return { ttl, key_type, memory_usage, count }
