# Fase 3 — OpenTelemetry PHP + Red Hat build of OpenTelemetry

## Objetivo

Instrumentar a aplicação **PHP 8.3 + Slim 4** usando a implementação upstream do OpenTelemetry para PHP e enviar os traces por OTLP/HTTP para um **Red Hat build of OpenTelemetry Collector** no OpenShift 4.20.

A fronteira de suporte do laboratório é explícita:

```text
Aplicação PHP
   |
   +-- OpenTelemetry PHP upstream
   |     - ext-opentelemetry
   |     - SDK
   |     - OTLP exporter
   |     - Slim auto-instrumentation
   |
   v
OTLP/HTTP :4318
   |
   v
Red Hat build of OpenTelemetry Collector
   |
   v
debug exporter
```

O `Instrumentation` CR do Red Hat build of OpenTelemetry **não é utilizado para PHP**, porque a Red Hat não lista PHP entre os runtimes suportados pelo mecanismo de injeção automática do Operator.

---

## 1. Arquivos substituídos em relação à Fase 2

Substitua no repositório:

```text
applications/php-demo/composer.json
applications/php-demo/Containerfile
applications/php-demo/openshift/20-deployment.yaml
```

Adicione:

```text
operators/opentelemetry/
platform/opentelemetry/
scripts/
docs/phase-03-opentelemetry.md
```

Não deve existir:

```text
applications/php-demo/openshift/50-instrumentation.yaml
```

---

## 2. Limpar tentativa anterior com Instrumentation CR

Se o pacote antigo da Fase 3 chegou a ser aplicado:

```bash
oc delete instrumentation instrumentation \
  -n php-observability-demo \
  --ignore-not-found=true
```

Remova a annotation antiga, caso exista:

```bash
oc annotate deployment php-demo \
  -n php-observability-demo \
  instrumentation.opentelemetry.io/inject-apache-httpd- \
  || true
```

Se você ainda não aplicou a versão anterior, esses comandos não causam impacto relevante.

---

## 3. Instalar o Red Hat build of OpenTelemetry Operator

```bash
oc apply -f operators/opentelemetry/00-namespace.yaml
oc apply -f operators/opentelemetry/10-operatorgroup.yaml
oc apply -f operators/opentelemetry/20-subscription.yaml
```

Valide:

```bash
oc get csv -n openshift-opentelemetry-operator
oc get pods -n openshift-opentelemetry-operator
```

Espere a CRD:

```bash
oc wait \
  --for=condition=Established \
  crd/opentelemetrycollectors.opentelemetry.io \
  --timeout=300s
```

---

## 4. Criar o Collector

```bash
oc apply -f platform/opentelemetry/00-namespace.yaml
oc apply -f platform/opentelemetry/10-collector.yaml
```

Valide:

```bash
oc get opentelemetrycollector otel -n observability
oc get deployment,pod,svc -n observability
```

O Collector disponibiliza:

```text
4317 -> OTLP/gRPC
4318 -> OTLP/HTTP
```

A aplicação PHP utilizará:

```text
http://otel-collector.observability.svc.cluster.local:4318
```

### Pipeline

```text
OTLP Receiver
     |
     v
memory_limiter
     |
     v
batch
     |
     v
debug exporter
```

---

## 5. Métricas internas do Collector

O CR possui:

```yaml
observability:
  metrics:
    enableMetrics: true
```

O objetivo é integrar as métricas operacionais do próprio Collector ao User Workload Monitoring já habilitado na Fase 1.

Verifique os recursos gerados:

```bash
oc get servicemonitor,podmonitor -n observability
```

Na console:

```text
Observe -> Targets
Source: User
```

Essas são métricas do **Collector**, e não métricas HTTP da aplicação PHP.

---

## 6. Instrumentação PHP

### Extensão nativa

A documentação upstream do OpenTelemetry exige a extensão `ext-opentelemetry` para auto-instrumentação.

O `Containerfile` instala:

```text
autoconf
gcc
make
php-devel
php-pear
```

durante o build e executa:

```bash
pecl install opentelemetry
```

Depois habilita:

```ini
extension=opentelemetry.so
```

e remove as ferramentas de compilação.

### Composer

O `composer.json` adiciona:

```text
open-telemetry/sdk
open-telemetry/exporter-otlp
open-telemetry/opentelemetry-auto-slim
php-http/guzzle7-adapter
```

A instrumentação Slim cria spans para o processamento HTTP do framework.

---

## 7. Rebuild da aplicação

Como o `Containerfile` e o `composer.json` mudaram, um novo build é obrigatório:

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

O build deve terminar em:

```text
Complete
```

---

## 8. Atualizar o Deployment

Aplique:

```bash
oc apply \
  -f applications/php-demo/openshift/20-deployment.yaml
```

Force novo rollout:

```bash
oc rollout restart deployment/php-demo \
  -n php-observability-demo
```

Aguarde:

```bash
oc rollout status deployment/php-demo \
  -n php-observability-demo
```

---

## 9. Configuração OpenTelemetry da aplicação

O Deployment define:

```text
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=php-demo
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector.observability.svc.cluster.local:4318
OTEL_PROPAGATORS=baggage,tracecontext
OTEL_RESOURCE_ATTRIBUTES=service.namespace=php-observability-demo
OTEL_PHP_LOG_DESTINATION=stderr
```

A aplicação conhece apenas o Collector.

Ela **não conhece Tempo, Prometheus ou qualquer backend final**.

---

## 10. Validar a extensão no Pod

```bash
POD=$(oc get pod \
  -n php-observability-demo \
  -l app=php-demo \
  -o jsonpath='{.items[0].metadata.name}')
```

```bash
oc exec \
  -n php-observability-demo \
  "${POD}" \
  -- php --ri opentelemetry
```

Também:

```bash
oc exec \
  -n php-observability-demo \
  "${POD}" \
  -- php -m | grep -i opentelemetry
```

Resultado esperado:

```text
opentelemetry
```

---

## 11. Validar dependências Composer

```bash
oc exec \
  -n php-observability-demo \
  "${POD}" \
  -- sh -c \
  'cd /opt/app-root/src && composer show | grep open-telemetry'
```

Devem aparecer, entre outros:

```text
open-telemetry/sdk
open-telemetry/exporter-otlp
open-telemetry/opentelemetry-auto-slim
```

---

## 12. Validar variáveis OTEL

```bash
oc exec \
  -n php-observability-demo \
  "${POD}" \
  -- env | grep '^OTEL_'
```

---

## 13. Gerar tráfego

```bash
./scripts/06-generate-traffic.sh
```

Ou manualmente:

```bash
ROUTE=$(oc get route php-demo \
  -n php-observability-demo \
  -o jsonpath='{.spec.host}')
```

```bash
curl -sk "https://${ROUTE}/"
curl -sk "https://${ROUTE}/users"
time curl -sk "https://${ROUTE}/slow"
curl -sk -o /dev/null -w '%{http_code}\n' \
  "https://${ROUTE}/error"
```

---

## 14. Validar traces no Collector

Identifique o Deployment:

```bash
oc get deployment -n observability
```

Depois:

```bash
oc logs \
  -n observability \
  deployment/<collector-deployment> \
  --tail=300
```

Procure por:

```text
ResourceSpans
```

e atributos como:

```text
service.name = php-demo
service.namespace = php-observability-demo
```

Também devem aparecer spans relacionados às rotas Slim.

Para acompanhar ao vivo:

Terminal 1:

```bash
./scripts/08-watch-collector-traces.sh
```

Terminal 2:

```bash
./scripts/06-generate-traffic.sh
```

---

## 15. O que esperar das rotas

### `/users`

Trace normal de requisição HTTP.

### `/slow`

O span da requisição deve apresentar duração próxima de 2 segundos.

### `/error`

Deve existir um span para a requisição que recebeu HTTP 500. A representação exata de status e atributos depende das semantic conventions e da versão das bibliotecas instaladas.

---

## 16. Critérios de conclusão

A Fase 3 está concluída quando:

- Red Hat build of OpenTelemetry Operator está instalado;
- o `OpenTelemetryCollector` está operacional;
- OTLP/HTTP está disponível na porta 4318;
- a imagem PHP foi reconstruída;
- `php --ri opentelemetry` funciona dentro do Pod;
- as bibliotecas OpenTelemetry estão presentes no Composer;
- `OTEL_PHP_AUTOLOAD_ENABLED=true`;
- `OTEL_SERVICE_NAME=php-demo`;
- a aplicação continua saudável;
- `/users`, `/slow` e `/error` continuam funcionando;
- o Collector recebe `ResourceSpans`;
- `service.name=php-demo` aparece nos traces;
- as métricas internas do Collector podem ser monitoradas pelo User Workload Monitoring.

---

## 17. Fronteira entre upstream e Red Hat

```text
OpenTelemetry PHP upstream
    |
    +-- instrumentação da aplicação
    +-- extensão PHP
    +-- SDK
    +-- Slim auto-instrumentation
    +-- OTLP exporter

Red Hat build of OpenTelemetry
    |
    +-- Operator
    +-- OpenTelemetryCollector
    +-- processamento da telemetria
    +-- integração com OpenShift
```

O contrato entre as duas partes é **OTLP**.

---

## Referências

### Red Hat

- OpenShift Container Platform 4.20 — Red Hat build of OpenTelemetry
  https://docs.redhat.com/en/documentation/openshift_container_platform/4.20/html-single/red_hat_build_of_opentelemetry/red_hat_build_of_opentelemetry

- Red Hat build of OpenTelemetry 3.9 — Installing
  https://docs.redhat.com/en/documentation/red_hat_build_of_opentelemetry/3.9/html-single/installing_red_hat_build_of_opentelemetry/index

- Red Hat build of OpenTelemetry 3.9 — Configuring the Collector
  https://docs.redhat.com/en/documentation/red_hat_build_of_opentelemetry/3.9/html-single/configuring_the_collector/index

- Red Hat maintained PHP 8.3 container source
  https://github.com/sclorg/s2i-php-container/blob/master/8.3/README.md

### OpenTelemetry upstream

- OpenTelemetry PHP
  https://opentelemetry.io/docs/languages/php/

- PHP zero-code auto-instrumentation
  https://opentelemetry.io/docs/zero-code/php/auto/

- PHP exporters
  https://opentelemetry.io/docs/languages/php/exporters/

- PHP SDK configuration
  https://opentelemetry.io/docs/languages/php/sdk/
