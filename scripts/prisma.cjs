const { loadEnvConfig } = require('@next/env')
const { spawnSync } = require('node:child_process')
loadEnvConfig(process.cwd())
const result = spawnSync(process.execPath, [require.resolve('prisma/build/index.js'), ...process.argv.slice(2)], { stdio: 'inherit', env: process.env })
if (result.error) throw result.error
process.exitCode = result.status ?? 1
