// player.js
const viaLangProxy = /\/api\/player\/(a-relay|lang-proxy)\b/i.test(String(abs || source?.url || ""));
const viaProxy = /\/api\/player\/(v-relay|a-relay|media-proxy|lang-proxy)\b/i.test(abs);
