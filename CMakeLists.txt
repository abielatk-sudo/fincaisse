cmake_minimum_required(VERSION 3.16)
project(fincaisse_engine LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)

add_executable(fincaisse_engine cpp/fincaisse_engine.cpp)

if (MSVC)
  target_compile_options(fincaisse_engine PRIVATE /W4 /O2)
else()
  target_compile_options(fincaisse_engine PRIVATE -Wall -Wextra -O2)
endif()

set_target_properties(fincaisse_engine PROPERTIES
  RUNTIME_OUTPUT_DIRECTORY "${CMAKE_SOURCE_DIR}/bin"
)
