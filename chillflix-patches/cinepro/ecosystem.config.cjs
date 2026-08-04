# PM2 ecosystem — fixed ports for nginx upstreams (do not change without updating nginx).
# On VPS: pm2 startOrReload infra/pm2/ecosystem.config.cjs --update-env && pm2 save

module.exports = {
    apps: [
        {
            name: "chillflix",
            cwd: "/var/www/chillflix.lol",
            script: "npm",
            args: "start",
            max_memory_restart: "900M",
            env: {
                NODE_ENV: "production",
                PORT: 3000,
            },
        },
        {
            name: "chillflix-player",
            cwd: "/var/www/chillflix.pw",
            script: "npm",
            args: "start",
            env: {
                NODE_ENV: "production",
                PORT: 3002,
            },
        },
        {
            name: "cinepro",
            cwd: "/var/www/cinepro",
            script: "dist/server.js",
            env: {
                NODE_ENV: "production",
                PORT: 3001,
                HOST: "127.0.0.1",
                CINEPRO_PROVIDER_ALLOWLIST:
                    "vidapiru,flixhqz,vaplayer,fsharetv,notorrent,videasy",
            },
        },
    ],
}
