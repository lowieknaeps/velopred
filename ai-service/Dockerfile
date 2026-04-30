FROM python:3.11-slim

WORKDIR /app

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

COPY requirements-ml.txt /app/requirements-ml.txt
RUN pip install --no-cache-dir -r /app/requirements-ml.txt

COPY app /app/app

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--log-level", "warning"]

