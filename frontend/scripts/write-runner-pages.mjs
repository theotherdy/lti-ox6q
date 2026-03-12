import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const distDir = resolve(process.cwd(), 'dist')

const openRunnerHtml = `<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Open React Runner</title>
  </head>
  <body>
    <div id="root"></div>
    <script src="./assets/open-react-runner.js"></script>
  </body>
</html>
`

const structuredRunnerHtml = `<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Structured Runner</title>
    <style>
      html, body, #root {
        margin: 0;
        padding: 0;
        width: 100%;
      }
      body {
        box-sizing: border-box;
      }
    </style>
  </head>
  <body>
    <div id="root"></div>
    <script src="./assets/structured-runner.js"></script>
  </body>
</html>
`

await mkdir(distDir, { recursive: true })
await writeFile(resolve(distDir, 'open-react-runner.html'), openRunnerHtml, 'utf8')
await writeFile(resolve(distDir, 'structured-runner.html'), structuredRunnerHtml, 'utf8')
