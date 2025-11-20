
from fastapi import FastAPI
from dotenv import load_dotenv
from agents.evaluator.agent import evaluator_agent
from google.adk.sessions import InMemorySessionService
from google.adk.runners import Runner
from pydantic import BaseModel
from google.genai.types import Content, Part
import json

load_dotenv()

app_title = "AI Service Orchestrator"

app = FastAPI(title=app_title)

session_service = InMemorySessionService()

runner = Runner(
    agent=evaluator_agent,
    app_name=app_title,
    session_service=session_service
)

class OrchestrateRequest(BaseModel):
    user_id: str
    session_id: str
    query: str

@app.post("/ai-agent")
async def orchestrate(request: OrchestrateRequest):
    session = await session_service.get_session(app_name=app_title, session_id=request.session_id, user_id=request.user_id)
    if not session:
        session = await session_service.create_session(app_name=app_title, session_id=request.session_id, user_id=request.user_id)
    results = []

    async for event in runner.run_async(
        user_id=request.user_id, session_id=session.id, new_message=Content(parts=[Part(text=request.query)])
    ):
        if event.content and event.content.parts:
            text = event.content.parts[0].text
            if text != "None" and text:
                try:
                    results.append(json.loads(text))
                except Exception:
                    results.append(text)
    if results:
        return {"responses": results[0]}
    else:
        return {"message": "No queries!"}

