# Fase 2 — Aplicação PHP Slim no OpenShift 4.20

## Objetivo

Disponibilizar uma aplicação PHP 8.3 com Slim 4 no OpenShift 4.20, sem OpenTelemetry nesta etapa.

A aplicação é construída pelo próprio OpenShift e exposta por uma `Route` TLS edge.

## Topologia

```text
Cliente
   |
   v
Route (TLS edge)
   |
   v
Service :8080
   |
   v
Deployment
   |
   v
ubi9/php-83
Apache 2.4 + PHP 8.3
   |
   v
Slim 4
```

## Recursos OpenShift

- `Namespace`
- `ImageStream`
- `BuildConfig`
- `Deployment`
- `Service`
- `Route`

## Por que Binary Build?

O código permanece versionado no repositório local e é enviado ao BuildConfig com:

```bash
oc start-build php-demo \
  -n php-observability-demo \
  --from-dir=applications/php-demo \
  --follow
```

O OpenShift usa a estratégia Docker/Buildah para processar o `Containerfile` e grava o resultado no `ImageStreamTag` `php-demo:latest`.

## Imagem base

```text
registry.access.redhat.com/ubi9/php-83:latest
```

A imagem é mantida pela Red Hat e contém Apache HTTP Server 2.4 com PHP 8.3. Ela também fornece os scripts S2I `assemble` e `run`.

O `Containerfile` aproveita esses scripts:

```dockerfile
RUN /usr/libexec/s2i/assemble
CMD ["/usr/libexec/s2i/run"]
```

A variável:

```text
DOCUMENTROOT=/public
```

faz o Apache utilizar o diretório `public` da aplicação como DocumentRoot.

## Health probes

O endpoint `/health` é utilizado para:

- `startupProbe`
- `readinessProbe`
- `livenessProbe`

Isso separa três verificações:

1. a aplicação terminou de iniciar;
2. está pronta para receber tráfego;
3. continua saudável durante a execução.

## Endpoints de teste

```text
/health  -> HTTP 200
/users   -> HTTP 200
/slow    -> HTTP 200 com ~2s de atraso
/error   -> HTTP 500
```

Esses comportamentos serão utilizados posteriormente na instrumentação OpenTelemetry.

## Execução

A partir da raiz do repositório:

```bash
oc apply -f applications/php-demo/openshift/00-namespace.yaml
```

Crie os recursos de build:

```bash
oc apply \
  -f applications/php-demo/openshift/10-imagestream.yaml \
  -f applications/php-demo/openshift/11-buildconfig.yaml
```

Execute o build:

```bash
oc start-build php-demo \
  -n php-observability-demo \
  --from-dir=applications/php-demo \
  --follow
```

Valide:

```bash
oc get builds -n php-observability-demo
oc get imagestream php-demo -n php-observability-demo
```

Implante a aplicação:

```bash
oc apply \
  -f applications/php-demo/openshift/20-deployment.yaml \
  -f applications/php-demo/openshift/30-service.yaml \
  -f applications/php-demo/openshift/40-route.yaml
```

Aguarde:

```bash
oc rollout status deployment/php-demo \
  -n php-observability-demo
```

## Validação dos pods

```bash
oc get pods -n php-observability-demo
```

Detalhes:

```bash
oc describe deployment php-demo \
  -n php-observability-demo
```

Valide as probes:

```bash
oc describe pod \
  -n php-observability-demo \
  -l app=php-demo
```

## Teste do Service internamente

```bash
oc run curl-test \
  -n php-observability-demo \
  --image=registry.access.redhat.com/ubi9/ubi-minimal:latest \
  --restart=Never \
  --rm -i \
  -- curl -s http://php-demo:8080/health
```

Resultado esperado:

```json
{
    "status": "UP",
    "service": "php-observability-demo"
}
```

## Teste da Route

Descubra o hostname:

```bash
oc get route php-demo \
  -n php-observability-demo
```

Ou:

```bash
ROUTE=$(oc get route php-demo \
  -n php-observability-demo \
  -o jsonpath='{.spec.host}')
```

Teste:

```bash
curl -sk "https://${ROUTE}/"
curl -sk "https://${ROUTE}/health"
curl -sk "https://${ROUTE}/users"
time curl -sk "https://${ROUTE}/slow"
curl -sk -o /dev/null -w '%{http_code}\n' "https://${ROUTE}/error"
```

O último teste deve retornar:

```text
500
```

## Critérios de conclusão

A Fase 2 está concluída quando:

- o build termina em `Complete`;
- `php-demo:latest` existe no ImageStream;
- o Deployment está `Available`;
- o Pod está `Ready`;
- startup, readiness e liveness probes estão funcionando;
- o Service responde na porta 8080;
- a Route HTTPS está admitida;
- `/health` retorna HTTP 200;
- `/slow` apresenta aproximadamente 2 segundos de latência;
- `/error` retorna HTTP 500.

## Referências Red Hat

- Red Hat Ecosystem Catalog — `ubi9/php-83`
- Repositório mantido pela comunidade Software Collections (`sclorg`) — PHP 8.3 container image
- OpenShift 4.20 — Builds using BuildConfig
- OpenShift 4.20 — Images / ImageStream change triggers
- OpenShift 4.20 — Application health
- OpenShift 4.20 — Routes / Ingress and load balancing
