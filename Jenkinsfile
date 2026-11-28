@Library(['dockerHelpers', 'testing', 'deploy', 'pipelines']) _

// eSoul's shared `laravelPipeline` step (library `pipelines`): checkout, static checks (composer validate,
// PHPStan, Pint, ESLint, Prettier, type check, dependency audit), PHP and Vitest tests, the asset build, a
// test build of the image on pull requests and, from the branches below, the multi-architecture image
// release. Stages, timeouts and credentials are described in `pipelines/vars/laravelPipeline.txt` of the
// eSoul-cz/jenkins-libraries repository.
// `esoul-guidelines check` runs by itself (stage `Guidelines check`, from the project's own `vendor/`) because the project has an
// `esoul-guidelines.lock`: nothing to configure here, and no `extraChecks` entry for it.
//
// Adopting the template: set `image` to the release image name of the application (lowercase letters,
// digits, `_`, `.`, `-`; it is pushed to the `registry` namespace) and, to deploy after the release, add
// `stack: '<komodo stack>'` to the deploy rule. Without `stack` nothing is deployed.
laravelPipeline(
    image: 'replace-with-app-name',
    nodeImage: 'node:26',

    // The step's default audit fails only on critical advisories. This replaces the default list with the
    // unfiltered audit that general/dependencies.md asks for; an advisory that cannot be fixed yet goes into
    // `auditConfig.ignoreGhsas` of pnpm-workspace.yaml, with a reason and a review date.
    nodeStaticChecks: [
        [name: 'Dependency audit', command: 'pnpm audit'],
    ],

    // Release images are built and pushed from these branches.
    deploy: [[branches: ['main', 'master']]],

    // Addons add options of the pipeline here (a slot of the template; keep this line).
    // @esoul-slot pipeline-options
)
