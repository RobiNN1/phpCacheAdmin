local key = KEYS[1]
local ttl = redis.call("TTL", key)
local key_type = redis.call("TYPE", key)["ok"]
local memory_usage = redis.call("MEMORY", "USAGE", key)
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
