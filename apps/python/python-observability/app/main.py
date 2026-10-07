import time
import requests
from fastapi import FastAPI, HTTPException
from opentelemetry import trace
from opentelemetry.trace import Status, StatusCode

# Obtém o tracer do OpenTelemetry
tracer = trace.get_tracer("python-observability-app", "1.0.0")

app = FastAPI(title="OTel Demo App - PGERJ")

@app.get("/")
def read_root():
    return {"status": "ok", "message": "Aplicação rodando no OCP"}

@app.get("/health")
def health_check():
    return {"status": "UP"}

@app.get("/processar-pedido/{pedido_id}")
def processar_pedido(pedido_id: str):
    # Span Pai: Mede o tempo total do processamento de negócio
    with tracer.start_as_current_span("processamento_pedido") as span:
        span.set_attribute("pedido.id", pedido_id)
        
        if pedido_id == "0":
            span.set_status(Status(StatusCode.ERROR, "ID inválido"))
            raise HTTPException(status_code=400, detail="Pedido inválido")

        # Chama a função interna (tempo medido individualmente)
        desconto = calcular_desconto(pedido_id)
        
        # Chama a consulta externa (tempo medido individualmente)
        status_externo = consultar_servico_externo()

        return {
            "pedido_id": pedido_id,
            "desconto": desconto,
            "status_externo": status_externo
        }

def calcular_desconto(pedido_id: str) -> float:
    # Sub-span 1: Mede especificamente o tempo desta função interna
    with tracer.start_as_current_span("funcao_calcular_desconto") as span:
        span.set_attribute("algoritmo", "v2")
        time.sleep(0.08)  # Simula 80ms de processamento
        return 15.5

def consultar_servico_externo() -> int:
    # Sub-span 2: A auto-instrumentação do 'requests' também criará um span filho aqui
    with tracer.start_as_current_span("funcao_consultar_servico_externo"):
        try:
            res = requests.get("https://httpbin.org/delay/1", timeout=5)
            return res.status_code
        except Exception as e:
            return 500