-- Prosody Token Moderation Module
-- Enforces moderator / owner role based strictly on room-scoped JWT token claims.
-- Users with context.user.moderator = true are granted MUC 'owner' (moderator).
-- Other participants are assigned 'member' (regular participant), regardless of join order.

local log = module._log;
local jid_bare = require "util.jid".bare;
local um_is_admin = require "core.usermanager".is_admin;
local basexx = require "basexx";
local json = require "cjson.safe";

local function is_admin(jid)
    return um_is_admin(jid, module.host);
end

local function is_token_moderator(session)
    if not session then
        return false;
    end

    -- 1. Check bound context user (set by mod_auth_token / token_util)
    local context_user = session.jitsi_meet_context_user;
    if context_user and (context_user.moderator == true or context_user.moderator == "true" or context_user.moderator == 1 or context_user.moderator == "1") then
        return true;
    end

    -- 2. Fallback: Parse auth_token claims if available
    local token = session.auth_token;
    if token and type(token) == "string" then
        local first_dot = token:find("%.");
        if first_dot then
            local second_dot = token:sub(first_dot + 1):find("%.");
            if second_dot then
                local payload_b64 = token:sub(first_dot + 1, first_dot + second_dot - 1);
                local decoded = basexx.from_url64(payload_b64);
                if decoded then
                    local payload = json.decode(decoded);
                    if payload then
                        if payload.context and payload.context.user and (payload.context.user.moderator == true or payload.context.user.moderator == "true") then
                            return true;
                        end
                        if payload.moderator == true or payload.moderator == "true" then
                            return true;
                        end
                    end
                end
            end
        end
    end

    return false;
end

module:log("info", "Initializing token_moderation module for MUC %s", module.host);

-- Prosody muc.lib.lua sets the first joiner as owner at priority -1 during muc-room-pre-create.
-- We run at priority -2 to override this: if the first joiner is not a token moderator, demote to member.
module:hook("muc-room-pre-create", function(event)
    local origin = event.origin;
    local bare_jid = jid_bare(event.stanza.attr.from);
    if is_admin(bare_jid) then
        return;
    end

    if not is_token_moderator(origin) then
        module:log("info", "Room pre-create: first occupant %s is not moderator, setting member affiliation", bare_jid);
        event.room:set_affiliation(true, bare_jid, "member");
    else
        module:log("info", "Room pre-create: first occupant %s is moderator, setting owner affiliation", bare_jid);
        event.room:set_affiliation(true, bare_jid, "owner");
    end
end, -2);

-- Hook muc-room-created to block unauthorized external elevation to owner
module:hook("muc-room-created", function(event)
    local room = event.room;
    local _set_affiliation = room.set_affiliation;
    room.set_affiliation = function(thisRoom, actor, jid, affiliation, reason)
        -- Allow internal server/plugin action (actor == true)
        if actor == true then
            return _set_affiliation(thisRoom, true, jid, affiliation, reason);
        elseif affiliation == "owner" then
            -- Disallow arbitrary or focus-driven auto-owner elevation
            module:log("warn", "Blocked attempt to grant owner affiliation to %s by actor %s", tostring(jid), tostring(actor));
            return nil, "modify", "not-acceptable";
        else
            return _set_affiliation(thisRoom, actor, jid, affiliation, reason);
        end
    end;
end);

module:hook("muc-occupant-pre-join", function(event)
    local room, occupant, session = event.room, event.occupant, event.origin;

    if not occupant or not occupant.bare_jid then
        return;
    end

    if is_admin(occupant.bare_jid) then
        return;
    end

    local bare_jid = occupant.bare_jid;
    local s = session or (prosody.full_sessions and prosody.full_sessions[occupant.jid]) or (prosody.bare_sessions and prosody.bare_sessions[bare_jid]);

    if is_token_moderator(s) then
        module:log("info", "Token moderator identified: granting owner affiliation to %s in %s", bare_jid, room.jid);
        room:set_affiliation(true, bare_jid, "owner");
        occupant.role = "moderator";
    else
        module:log("info", "Regular participant identified: setting member affiliation for %s in %s", bare_jid, room.jid);
        room:set_affiliation(true, bare_jid, "member");
        occupant.role = "participant";
    end
end, 10);

module:hook("muc-occupant-joined", function(event)
    local room, occupant, session = event.room, event.occupant, event.origin;

    if not occupant or not occupant.bare_jid then
        return;
    end

    if is_admin(occupant.bare_jid) then
        return;
    end

    local bare_jid = occupant.bare_jid;
    local s = session or (prosody.full_sessions and prosody.full_sessions[occupant.jid]) or (prosody.bare_sessions and prosody.bare_sessions[bare_jid]);

    if is_token_moderator(s) then
        room:set_affiliation(true, bare_jid, "owner");
        occupant.role = "moderator";
    else
        room:set_affiliation(true, bare_jid, "member");
        occupant.role = "participant";
    end
end, 10);
